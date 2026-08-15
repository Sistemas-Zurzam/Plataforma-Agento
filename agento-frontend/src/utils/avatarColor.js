export const COLOR_PALETTE = [
  '#014693',
  '#1c6fe0',
  '#0d9488',
  '#7c3aed',
  '#c2410c',
  '#0f766e',
  '#be185d',
  '#ca8a04',
];

export function colorForName(name = '') {
  const hash = [...name].reduce((acc, char) => acc + char.charCodeAt(0), 0);
  return COLOR_PALETTE[hash % COLOR_PALETTE.length];
}

export function initialsForName(name = '') {
  const parts = name
    .trim()
    .split(/\s+/)
    .filter((part) => /^\p{L}/u.test(part));
  const initials = parts.slice(0, 2).map((part) => part.charAt(0).toUpperCase());
  return initials.join('') || '?';
}
