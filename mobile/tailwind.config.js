/** @type {import('tailwindcss').Config} */
module.exports = {
  // lib/ and context/ too: class names kept there (e.g. the booking status
  // badge colours in lib/appointments.ts) are otherwise never generated, so
  // those badges render unstyled.
  content: [
    './app/**/*.{js,jsx,ts,tsx}',
    './components/**/*.{js,jsx,ts,tsx}',
    './lib/**/*.{js,jsx,ts,tsx}',
    './context/**/*.{js,jsx,ts,tsx}',
  ],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      // Design tokens (docs/ROLE_UI_SPEC.md, Part 2) — same navy as the web app.
      colors: {
        brand: '#0b1636',
      },
    },
  },
  plugins: [],
};
