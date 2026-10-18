import { useSyncExternalStore } from 'react'
import { connectionStatus, subscribeConnection } from '../lib/realtime'
import { useOnline } from './useOnline'

export function useConnectionStatus() {
  const online = useOnline()
  const status = useSyncExternalStore(subscribeConnection, connectionStatus, () => 'connecting')
  return online ? status : 'offline'
}
