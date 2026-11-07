import { Modal, Pressable, View } from 'react-native';
import { Text } from './Text';

export interface MenuItem {
  label: string;
  onPress: () => void;
  /** Shown in red, for actions like logging out. */
  destructive?: boolean;
}

/**
 * A pop-up menu with as many options as it needs. Not Alert.alert: Android
 * shows at most three buttons in an alert and silently drops the rest, which
 * is how "Log out" once disappeared from this menu.
 */
export function AccountMenu({
  visible,
  title,
  subtitle,
  items,
  onClose,
}: {
  visible: boolean;
  title: string;
  subtitle?: string;
  items: MenuItem[];
  onClose: () => void;
}) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
      <Pressable className="flex-1 justify-center bg-black/40 px-8" onPress={onClose} accessibilityLabel="Close menu">
        {/* Swallows taps so touching the card itself doesn't close the menu. */}
        <Pressable onPress={() => {}} className="overflow-hidden rounded-2xl bg-white">
          <View className="px-5 pb-3 pt-5">
            <Text className="text-lg font-semibold text-slate-900">{title}</Text>
            {subtitle ? <Text className="mt-0.5 text-sm text-slate-500">{subtitle}</Text> : null}
          </View>

          {items.map((item) => (
            <Pressable
              key={item.label}
              onPress={() => {
                onClose();
                item.onPress();
              }}
              accessibilityRole="button"
              className="border-t border-slate-100 px-5 py-4 active:bg-slate-50"
            >
              <Text className={`text-base font-medium ${item.destructive ? 'text-red-600' : 'text-slate-800'}`}>{item.label}</Text>
            </Pressable>
          ))}

          <Pressable onPress={onClose} accessibilityRole="button" className="border-t border-slate-100 px-5 py-4 active:bg-slate-50">
            <Text className="text-base font-medium text-slate-500">Cancel</Text>
          </Pressable>
        </Pressable>
      </Pressable>
    </Modal>
  );
}
