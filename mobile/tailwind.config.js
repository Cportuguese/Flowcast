/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./app/**/*.{js,jsx,ts,tsx}', './components/**/*.{js,jsx,ts,tsx}'],
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
