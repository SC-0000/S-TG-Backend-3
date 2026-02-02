// tailwind.config.js
import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import scrollbar from 'tailwind-scrollbar';
import typography from '@tailwindcss/typography';

const withOpacity = (variable, fallbackRgb) =>
  `rgb(var(${variable}, ${fallbackRgb}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './storage/framework/views/*.php',
    './resources/views/**/*.blade.php',
    './resources/js/**/*.jsx',
    './resources/**/*.js',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Figtree', ...defaultTheme.fontFamily.sans],
        nunito: ['Nunito', 'sans-serif'],
        poppins: ['Poppins', 'sans-serif'],
      },
      colors: {
        primary: {
          DEFAULT: withOpacity('--color-primary-rgb', '65 17 131'),
          50: withOpacity('--color-primary-50-rgb', '248 246 255'),
          100: withOpacity('--color-primary-100-rgb', '240 235 255'),
          200: withOpacity('--color-primary-200-rgb', '225 214 255'),
          300: withOpacity('--color-primary-300-rgb', '201 184 255'),
          400: withOpacity('--color-primary-400-rgb', '166 136 255'),
          500: withOpacity('--color-primary-500-rgb', '139 92 246'),
          600: withOpacity('--color-primary-600-rgb', '124 58 237'),
          700: withOpacity('--color-primary-700-rgb', '109 40 217'),
          800: withOpacity('--color-primary-800-rgb', '91 33 182'),
          900: withOpacity('--color-primary-900-rgb', '65 17 131'),
          950: withOpacity('--color-primary-950-rgb', '46 15 92'),
        },
        accent: {
          DEFAULT: withOpacity('--color-accent-rgb', '31 109 242'),
          50: withOpacity('--color-accent-50-rgb', '239 246 255'),
          100: withOpacity('--color-accent-100-rgb', '219 234 254'),
          200: withOpacity('--color-accent-200-rgb', '191 219 254'),
          300: withOpacity('--color-accent-300-rgb', '147 197 253'),
          400: withOpacity('--color-accent-400-rgb', '96 165 250'),
          500: withOpacity('--color-accent-500-rgb', '59 130 246'),
          600: withOpacity('--color-accent-600-rgb', '37 99 235'),
          700: withOpacity('--color-accent-700-rgb', '29 78 216'),
          800: withOpacity('--color-accent-800-rgb', '30 64 175'),
          900: withOpacity('--color-accent-900-rgb', '31 109 242'),
          950: withOpacity('--color-accent-950-rgb', '23 37 84'),
        },
        'accent-soft': {
          DEFAULT: withOpacity('--color-accent-soft-rgb', '247 112 82'),
          50: withOpacity('--color-accent-soft-50-rgb', '255 247 245'),
          100: withOpacity('--color-accent-soft-100-rgb', '255 237 232'),
          200: withOpacity('--color-accent-soft-200-rgb', '255 217 208'),
          300: withOpacity('--color-accent-soft-300-rgb', '255 186 168'),
          400: withOpacity('--color-accent-soft-400-rgb', '255 149 128'),
          500: withOpacity('--color-accent-soft-500-rgb', '255 169 150'),
          600: withOpacity('--color-accent-soft-600-rgb', '255 107 71'),
          700: withOpacity('--color-accent-soft-700-rgb', '240 74 35'),
          800: withOpacity('--color-accent-soft-800-rgb', '199 62 29'),
          900: withOpacity('--color-accent-soft-900-rgb', '163 52 26'),
          950: withOpacity('--color-accent-soft-950-rgb', '107 31 18'),
        },
        secondary: {
          DEFAULT: withOpacity('--color-secondary-rgb', '180 200 232'),
          50: withOpacity('--color-secondary-50-rgb', '245 248 255'),
          100: withOpacity('--color-secondary-100-rgb', '232 240 254'),
          200: withOpacity('--color-secondary-200-rgb', '213 227 251'),
          300: withOpacity('--color-secondary-300-rgb', '194 212 244'),
          400: withOpacity('--color-secondary-400-rgb', '175 197 237'),
          500: withOpacity('--color-secondary-500-rgb', '180 200 232'),
          600: withOpacity('--color-secondary-600-rgb', '144 171 214'),
          700: withOpacity('--color-secondary-700-rgb', '111 141 185'),
          800: withOpacity('--color-secondary-800-rgb', '78 112 155'),
          900: withOpacity('--color-secondary-900-rgb', '55 89 132'),
          950: withOpacity('--color-secondary-950-rgb', '36 59 99'),
        },
        heavy: {
          DEFAULT: withOpacity('--color-heavy-rgb', '31 109 242'),
          50: withOpacity('--color-heavy-50-rgb', '234 241 255'),
          100: withOpacity('--color-heavy-100-rgb', '213 228 255'),
          200: withOpacity('--color-heavy-200-rgb', '171 198 255'),
          300: withOpacity('--color-heavy-300-rgb', '128 167 255'),
          400: withOpacity('--color-heavy-400-rgb', '78 134 247'),
          500: withOpacity('--color-heavy-500-rgb', '47 111 244'),
          600: withOpacity('--color-heavy-600-rgb', '31 109 242'),
          700: withOpacity('--color-heavy-700-rgb', '28 87 209'),
          800: withOpacity('--color-heavy-800-rgb', '25 73 179'),
          900: withOpacity('--color-heavy-900-rgb', '23 61 151'),
          950: withOpacity('--color-heavy-950-rgb', '15 39 95'),
        },
        'gray-900': '#111827',
        'gray-600': '#4B5563',
        'gray-100': '#F5F7FC',
        white: '#FFFFFF',
        glass: {
          light: 'rgba(255, 255, 255, 0.1)',
          medium: 'rgba(255, 255, 255, 0.2)',
          heavy: 'rgba(255, 255, 255, 0.3)',
        },
      },
      keyframes: { /* unchanged */ },
      animation: { /* unchanged */ },
    },
  },
  plugins: [forms, scrollbar, typography],
};
