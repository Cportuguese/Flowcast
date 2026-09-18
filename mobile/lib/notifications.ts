import Constants, { ExecutionEnvironment } from 'expo-constants';
import { api } from './api';

function isExpoGo() {
  return (
    Constants.appOwnership === 'expo' ||
    Constants.executionEnvironment === ExecutionEnvironment.StoreClient
  );
}

/**
 * Expo Go (SDK 53+) removed remote push notification support entirely —
 * merely touching `expo-notifications` there throws. Everything below is
 * dynamically imported and skipped in Expo Go so the queue screen keeps
 * working via WebSocket even without a development build.
 */
export async function registerForQueuePushNotifications() {
  if (isExpoGo()) {
    return;
  }

  try {
    const [Device, Notifications, { Platform }] = await Promise.all([
      import('expo-device'),
      import('expo-notifications'),
      import('react-native'),
    ]);

    if (!Device.isDevice) {
      return;
    }

    Notifications.setNotificationHandler({
      handleNotification: async () => ({
        shouldShowBanner: true,
        shouldShowList: true,
        shouldPlaySound: true,
        shouldSetBadge: false,
      }),
    });

    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('default', {
        name: 'default',
        importance: Notifications.AndroidImportance.HIGH,
      });
    }

    const existing = await Notifications.getPermissionsAsync();
    let status = existing.status;

    if (status !== 'granted') {
      const requested = await Notifications.requestPermissionsAsync();
      status = requested.status;
    }

    if (status !== 'granted') {
      return;
    }

    const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;

    const { data: pushToken } = await Notifications.getExpoPushTokenAsync(
      projectId ? { projectId } : undefined,
    );

    await api.patch('/user/push-token', { push_token: pushToken });
  } catch {
    // Push registration is best-effort — the live queue screen still works via WebSocket without it.
  }
}
