import Ionicons from '@expo/vector-icons/Ionicons';
import { ActivityIndicator, Pressable, View } from 'react-native';
import { Text } from './Text';
import { MONTH_NAMES, WEEKDAY_SHORT, dateKey } from '../lib/time';

/** One day as GET /appointments/calendar describes it. */
export interface CalendarDay {
  date: string;
  status: 'open' | 'full' | 'closed' | 'event' | 'past' | 'too_far';
  free_slots: number;
  event: string | null;
}

interface Props {
  /** Any date inside the month to show. */
  month: Date;
  /** null while the month is loading. */
  days: Record<string, CalendarDay> | null;
  selected: string | null;
  onSelect: (day: CalendarDay) => void;
  onPrevious: () => void;
  onNext: () => void;
  canGoBack: boolean;
  canGoForward: boolean;
}

const CELL_HEIGHT = 46;

/**
 * A month grid for booking: days the professional can still take patients
 * are bold with a green dot, full days and event days are marked, and
 * everything else is greyed out. Tapping a bookable day selects it.
 */
export function MonthCalendar({ month, days, selected, onSelect, onPrevious, onNext, canGoBack, canGoForward }: Props) {
  const first = new Date(month.getFullYear(), month.getMonth(), 1);
  const daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
  const today = dateKey(new Date());

  // Leading blanks so the 1st lands under its weekday.
  const cells: Array<number | null> = [...Array(first.getDay()).fill(null), ...Array.from({ length: daysInMonth }, (_, i) => i + 1)];
  while (cells.length % 7 !== 0) cells.push(null);

  return (
    <View className="rounded-2xl border border-slate-200 bg-white p-3">
      <View className="flex-row items-center justify-between px-1 pb-2">
        <Pressable
          onPress={onPrevious}
          disabled={!canGoBack}
          hitSlop={10}
          accessibilityLabel="Previous month"
          className={canGoBack ? '' : 'opacity-30'}
        >
          <Ionicons name="chevron-back" size={22} color="#0b1636" />
        </Pressable>
        <Text className="text-base font-semibold text-slate-900">
          {MONTH_NAMES[month.getMonth()]} {month.getFullYear()}
        </Text>
        <Pressable
          onPress={onNext}
          disabled={!canGoForward}
          hitSlop={10}
          accessibilityLabel="Next month"
          className={canGoForward ? '' : 'opacity-30'}
        >
          <Ionicons name="chevron-forward" size={22} color="#0b1636" />
        </Pressable>
      </View>

      <View className="flex-row">
        {WEEKDAY_SHORT.map((name) => (
          <Text key={name} className="flex-1 pb-1 text-center text-xs font-medium text-slate-400">
            {name}
          </Text>
        ))}
      </View>

      {days === null ? (
        <View style={{ height: CELL_HEIGHT * (cells.length / 7) }} className="items-center justify-center">
          <ActivityIndicator />
        </View>
      ) : (
        <View className="flex-row flex-wrap">
          {cells.map((dayNumber, index) => {
            if (dayNumber === null) {
              return <View key={`blank-${index}`} style={{ width: `${100 / 7}%`, height: CELL_HEIGHT }} />;
            }

            const key = dateKey(new Date(month.getFullYear(), month.getMonth(), dayNumber));
            const info = days[key];
            const status = info?.status ?? 'closed';
            const isSelected = selected === key;
            const tappable = status === 'open' || status === 'event' || status === 'full';
            const label = `${MONTH_NAMES[month.getMonth()]} ${dayNumber}: ${
              status === 'open' ? `${info?.free_slots} free` : status === 'full' ? 'fully booked' : status === 'event' ? 'no bookings' : 'not available'
            }`;

            return (
              <Pressable
                key={key}
                onPress={() => info && onSelect(info)}
                disabled={!tappable}
                accessibilityRole="button"
                accessibilityLabel={label}
                accessibilityState={{ selected: isSelected, disabled: !tappable }}
                style={{ width: `${100 / 7}%`, height: CELL_HEIGHT }}
                className="items-center justify-center"
              >
                <View
                  className={`h-9 w-9 items-center justify-center rounded-full ${
                    isSelected ? 'bg-brand' : key === today ? 'border border-slate-300' : ''
                  }`}
                >
                  <Text
                    className={`text-sm ${
                      isSelected
                        ? 'font-bold text-white'
                        : status === 'open'
                          ? 'font-semibold text-slate-900'
                          : 'text-slate-300'
                    }`}
                  >
                    {dayNumber}
                  </Text>
                </View>
                <View
                  className={`mt-0.5 h-1.5 w-1.5 rounded-full ${
                    status === 'open' ? 'bg-green-500' : status === 'full' ? 'bg-red-300' : status === 'event' ? 'bg-amber-400' : 'bg-transparent'
                  }`}
                />
              </Pressable>
            );
          })}
        </View>
      )}

      <View className="mt-2 flex-row flex-wrap justify-center gap-x-4 gap-y-1 border-t border-slate-100 pt-2">
        <LegendDot className="bg-green-500" label="Open" />
        <LegendDot className="bg-red-300" label="Full" />
        <LegendDot className="bg-amber-400" label="Clinic event" />
        <Text className="text-xs text-slate-400">Grey: not in that day</Text>
      </View>
    </View>
  );
}

function LegendDot({ className, label }: { className: string; label: string }) {
  return (
    <View className="flex-row items-center gap-1">
      <View className={`h-2 w-2 rounded-full ${className}`} />
      <Text className="text-xs text-slate-500">{label}</Text>
    </View>
  );
}
