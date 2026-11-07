import { createContext, useContext } from 'react';
import {
  Text as RNText,
  TextInput as RNTextInput,
  type TextInputProps,
  type TextProps,
} from 'react-native';

/**
 * Text and TextInput in the Flowcast brand font (Atkinson Hyperlegible Next).
 * Import these instead of the react-native ones.
 *
 * Why a wrapper: with a custom font, React Native ignores `font-bold` (it
 * cannot pick the bold file by weight, and Android fakes a smeared bold). So
 * the weight class is swapped for the matching font file here, and every
 * screen keeps writing ordinary `font-semibold` / `font-bold` classes.
 * The fonts are loaded in app/_layout.tsx.
 */
export const BRAND_FONT = {
  normal: 'AtkinsonHyperlegibleNext_400Regular',
  medium: 'AtkinsonHyperlegibleNext_500Medium',
  semibold: 'AtkinsonHyperlegibleNext_600SemiBold',
  bold: 'AtkinsonHyperlegibleNext_700Bold',
} as const;

type Weight = keyof typeof BRAND_FONT;
const WEIGHT_CLASS = /\bfont-(normal|medium|semibold|bold)\b/g;

function splitWeight(className: string | undefined): { weight?: Weight; rest?: string } {
  if (!className) return {};
  const match = [...className.matchAll(WEIGHT_CLASS)].pop();
  return {
    weight: match?.[1] as Weight | undefined,
    rest: className.replace(WEIGHT_CLASS, ' ').trim(),
  };
}

// True inside another <Text>, where a child without its own weight should
// inherit the parent's font (e.g. a bold word inside a sentence).
const InsideText = createContext(false);

export function Text({ className, style, ...props }: TextProps) {
  const nested = useContext(InsideText);
  const { weight, rest } = splitWeight(className);
  const font = weight || !nested ? { fontFamily: BRAND_FONT[weight ?? 'normal'] } : null;

  return (
    <InsideText.Provider value>
      <RNText {...props} className={rest} style={[font, style]} />
    </InsideText.Provider>
  );
}

export function TextInput({ className, style, ...props }: TextInputProps) {
  const { weight, rest } = splitWeight(className);
  return (
    <RNTextInput
      {...props}
      className={rest}
      style={[{ fontFamily: BRAND_FONT[weight ?? 'normal'] }, style]}
    />
  );
}
