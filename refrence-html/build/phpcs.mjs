/**
 * Runs PHP_CodeSniffer over the theme and the companion plugin.
 *
 * Composer's `vendor/bin/phpcs` is a shell script with no shebang support on
 * Windows, so `npm run` hands it to cmd.exe and it fails. Composer also writes
 * a .bat wrapper there; this picks whichever one the platform can execute and
 * runs it with the working directory set to wordpress/, where phpcs.xml.dist
 * and its relative <file> paths make sense.
 *
 * Usage: node build/phpcs.mjs [--fix] [extra phpcs args]
 */
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';

const root = 'wordpress';
const args = process.argv.slice(2);
const fix = args.includes('--fix');
const compat = args.includes('--compat');
const passthrough = args.filter(a => a !== '--fix' && a !== '--compat');

if (compat) {
  passthrough.unshift('--standard=phpcompat.xml.dist');
}

const tool = fix ? 'phpcbf' : 'phpcs';
const candidates = process.platform === 'win32'
  ? [`${tool}.bat`, tool]
  : [tool, `${tool}.bat`];

const binary = candidates
  .map(name => join('vendor', 'bin', name))
  .find(path => existsSync(join(root, path)));

if (!binary) {
  console.error(`  ${tool} is not installed. Run: cd wordpress && composer install`);
  process.exit(1);
}

const result = spawnSync(binary, passthrough, {
  cwd: root,
  stdio: 'inherit',
  shell: true,
});

/*
 * phpcbf exits 1 when it fixed something and 2 when something was left over.
 * Only the latter is a failure worth stopping a build for.
 */
const code = result.status ?? 1;
process.exit(fix ? (code > 1 ? code : 0) : code);
