import { Image } from 'react-native';

/**
 * The official Flowcast mark (navy, transparent background). The PNG files
 * (logo.png, @2x, @3x) are generated from brand/logo-mark.svg by
 * `npm run build` in brand/; never edit or recolor them by hand.
 */
export function LogoMark({ size = 48 }: { size?: number }) {
  return (
    <Image
      source={require('../assets/logo.png')}
      accessibilityLabel="Flowcast"
      resizeMode="contain"
      style={{ width: size, height: size }}
    />
  );
}
