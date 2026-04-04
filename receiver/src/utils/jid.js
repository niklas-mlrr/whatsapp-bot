/**
 * JID (Jabber ID) utility functions for WhatsApp
 */

/**
 * Resolves a chat JID (Jabber ID) to proper WhatsApp format.
 * Handles group JIDs (@g.us), user JIDs (@s.whatsapp.net), and bare phone numbers.
 *
 * @param {string} value - The chat identifier (phone number, JID, or group ID)
 * @returns {string} The properly formatted WhatsApp JID
 */
export function resolveChatJid(value = '') {
    if (!value) {
        return value;
    }
    // Group JIDs stay as-is
    if (value.endsWith('@g.us') || value.endsWith('@s.whatsapp.net')) {
        return value;
    }
    // Already has @ symbol - convert to @s.whatsapp.net
    if (value.includes('@')) {
        return `${value.split('@')[0]}@s.whatsapp.net`;
    }
    // Bare number - add @s.whatsapp.net suffix
    return `${value}@s.whatsapp.net`;
}

export default {
    resolveChatJid
};