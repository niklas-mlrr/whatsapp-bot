import readline from 'readline'
import P from 'pino'
import makeWASocket, {
  DisconnectReason,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
  useMultiFileAuthState,
} from '@whiskeysockets/baileys'

const logger = P({ level: 'info' })

const stayConnected = process.argv.includes('--stay')

const rl = readline.createInterface({ input: process.stdin, output: process.stdout })
const question = (text) => new Promise((resolve) => rl.question(text, resolve))

const AUTH_DIR = 'baileys_pairing_test_auth'

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

function shutdown(code = 0) {
  try {
    rl.close()
  } catch {
    // ignore
  }

  if (activeSock) {
    try {
      activeSock.end()
    } catch {
      // ignore
    }
    activeSock = null
  }

  const t = setTimeout(() => {
    process.exit(code)
  }, 250)
  t.unref?.()
}

let activeSock = null

async function createSocket() {
  const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR)
  const { version, isLatest } = await fetchLatestBaileysVersion()
  console.log(`Using WA Web version: ${version.join('.')} (isLatest: ${isLatest})`)

  const sock = makeWASocket({
    version,
    logger,
    auth: {
      creds: state.creds,
      keys: makeCacheableSignalKeyStore(state.keys, logger),
    },
    printQRInTerminal: false,
  })

  sock.ev.on('creds.update', saveCreds)

  return sock
}

async function start() {
  while (true) {
    if (activeSock) {
      try {
        activeSock.end()
      } catch {
        // ignore
      }
      activeSock = null
    }

    const sock = await createSocket()
    activeSock = sock

    if (!sock.authState.creds.registered) {
      const rawPhone = await question(
        'Enter your phone number in international format (digits only, e.g. 491701234567):\n'
      )

      const phoneNumber = String(rawPhone).replace(/\D/g, '')
      if (!phoneNumber) {
        throw new Error('Phone number is empty/invalid')
      }

      const code = await sock.requestPairingCode(phoneNumber)
      console.log(`\nPairing code: ${code}\n`)
      console.log(
        'On your phone: WhatsApp -> Linked devices -> Link a device -> Link with phone number, then enter the code.'
      )
    } else {
      console.log(`Already registered in ./${AUTH_DIR}/ — starting connection...`)
    }

    const outcome = await new Promise((resolve) => {
      sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect } = update
        if (connection === 'open') {
          resolve({ type: 'open' })
        }

        if (connection === 'close') {
          const statusCode = lastDisconnect?.error?.output?.statusCode
          const message = lastDisconnect?.error?.message
          resolve({ type: 'close', statusCode, message })
        }
      })
    })

    if (outcome.type === 'open') {
      console.log('Connected ✅')
      console.log('Logged in as:', sock.user)
      console.log(`Auth state saved to ./${AUTH_DIR}/`)

      if (!stayConnected) {
        shutdown(0)
        return
      }

      await new Promise(() => {})
    }

    if (outcome.type === 'close') {
      const { statusCode, message } = outcome

      if (statusCode === DisconnectReason.loggedOut) {
        if (String(message ?? '').toLowerCase().includes('conflict')) {
          console.log('Disconnected due to a conflict (device was removed on WhatsApp).')
          console.log('Fix: on your phone -> Linked devices, ensure this new device remains linked.')
          console.log('Also ensure no other Baileys/WhatsApp-web client is running for this number.')
        }
        console.log('Logged out. Delete the auth folder and run again:', AUTH_DIR)
        shutdown(0)
        return
      }

      console.log(`Connection closed (${statusCode ?? 'unknown'}${message ? `: ${message}` : ''}). Reconnecting...`)
      await sleep(2500)
    }
  }
}

start().catch((err) => {
  console.error('Pairing test failed:', err)
  shutdown(1)
})
