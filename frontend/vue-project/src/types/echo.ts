/**
 * Laravel Echo and Pusher type definitions
 */

export interface PusherConnection {
  bind: (event: string, callback: (...args: unknown[]) => void) => void;
  unbind: (event: string, callback?: (...args: unknown[]) => void) => void;
  socket_id: string;
}

export interface PusherChannel {
  bind: (event: string, callback: (...args: unknown[]) => void) => void;
  unbind: (event: string, callback?: (...args: unknown[]) => void) => void;
  trigger: (event: string, data: unknown) => void;
}

export interface PusherConnector {
  pusher: {
    connection: PusherConnection;
  };
}

export interface PrivateChannel extends PusherChannel {
  listen: (event: string, callback: (data: unknown) => void) => PrivateChannel;
  listenForWhisper: (event: string, callback: (data: unknown) => void) => PrivateChannel;
  whisper: (event: string, data: unknown) => void;
  stopListening: (event: string, callback?: (data: unknown) => void) => PrivateChannel;
}

export interface PresenceChannel extends PrivateChannel {
  here: (callback: (members: unknown[]) => void) => PresenceChannel;
  joining: (callback: (member: unknown) => void) => PresenceChannel;
  leaving: (callback: (member: unknown) => void) => PresenceChannel;
  whisper: (event: string, data: unknown) => void;
}

export interface EchoInstance {
  private: (channel: string) => PrivateChannel;
  join: (channel: string) => PresenceChannel;
  leave: (channel: string) => void;
  leaveChannel: (channel: string) => void;
  disconnect: () => void;
  socketId: () => string;
  connector: PusherConnector;
}

export interface EchoOptions {
  broadcaster: 'pusher' | 'reverb';
  key: string;
  cluster?: string;
  wsHost?: string;
  wsPort?: number;
  wssPort?: number;
  forceTLS?: boolean;
  enabledTransports?: ('ws' | 'wss')[];
  disableStats?: boolean;
  auth?: {
    headers?: Record<string, string>;
  };
  authEndpoint?: string;
}

// Extend Window interface for global Echo/Pusher
declare global {
  interface Window {
    Pusher: unknown;
    Echo: EchoInstance | null;
  }
}