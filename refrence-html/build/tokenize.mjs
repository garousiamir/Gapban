/**
 * Post-build pass: make every brand colour follow its CSS variable.
 *
 * Tailwind v4 compiles an alpha variant such as `bg-brand/20` into two tiers:
 *
 *   background-color: color-mix(in srgb, #ef4b4b 20%, transparent);        <- literal hex
 *   @supports (color: color-mix(in lab, red, red)) {
 *     background-color: color-mix(in oklab, var(--color-brand) 20%, transparent);
 *   }
 *
 * The second tier follows `--color-brand`, the first does not. Without this pass a
 * theme-options colour change would retint solid brand surfaces but leave every
 * tinted surface on the original red in browsers that lack `color-mix(in lab, ...)`.
 *
 * Rewriting the literal to `var(--color-brand)` costs nothing: any browser that can
 * parse `color-mix()` at all can resolve a `var()` inside it, and browsers that cannot
 * drop both tiers exactly as they do today.
 *
 * Usage: node build/tokenize.mjs <css-file> [...]
 */
import { readFileSync, writeFileSync } from 'node:fs';

/* Tokens declared in the `@theme` block of src/input.css. A token is only rewritten
   where it appears as a *value*, never where it is declared. */
const TOKENS = [
  ['#ef4b4b', '--color-brand'],
  ['#d33c3c', '--color-brand-dark'],
];

const files = process.argv.slice(2);
if (files.length === 0) {
  console.error('usage: node build/tokenize.mjs <css-file> [...]');
  process.exit(1);
}

let failed = false;

for (const file of files) {
  let css = readFileSync(file, 'utf8');
  const before = css;
  let rewritten = 0;

  for (const [hex, token] of TOKENS) {
    /* Skip the declaration itself (`--color-brand: #ef4b4b`) and rewrite every other
       occurrence, whatever the case Tailwind emitted it in. */
    const pattern = new RegExp(`(--[\\w-]+:\\s*)?${hex}\\b`, 'gi');
    css = css.replace(pattern, (match, declaration) => {
      if (declaration) return match;
      rewritten++;
      return `var(${token})`;
    });
  }

  /* The declarations must survive, or the variables resolve to nothing. */
  for (const [hex, token] of TOKENS) {
    if (!new RegExp(`${token}:\\s*${hex}`, 'i').test(css)) {
      console.error(`  FAIL  ${file}: lost the declaration for ${token}`);
      failed = true;
    }
  }

  /* Nothing outside a declaration may still hold a literal token colour. */
  for (const [hex, token] of TOKENS) {
    const stray = css
      .split('\n')
      .filter(line => new RegExp(hex, 'i').test(line) && !new RegExp(`${token}:`, 'i').test(line));
    if (stray.length) {
      console.error(`  FAIL  ${file}: ${stray.length} literal ${hex} left outside a declaration`);
      console.error('        ' + stray[0].trim());
      failed = true;
    }
  }

  if (css !== before) writeFileSync(file, css);
  console.log(`  ${file}  ${rewritten} literal(s) -> var()`);
}

process.exit(failed ? 1 : 0);
