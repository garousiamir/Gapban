/**
 * Reproducible production build: TypeScript → minified IIFE bundles in gapban/assets/dist.
 *   node tools/build.mjs [--watch]
 */
import * as esbuild from 'esbuild';
import { mkdirSync, readFileSync, writeFileSync, statSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { gzipSync } from 'node:zlib';

const out = 'gapban/assets/dist';
mkdirSync(out, { recursive: true });

// CSS imported from TS is minified and inlined as a string (it is injected into a shadow root).
const cssText = {
  name: 'css-text',
  setup(build) {
    build.onLoad({ filter: /\.css$/ }, async (args) => {
      const src = readFileSync(args.path, 'utf8');
      const min = await esbuild.transform(src, { loader: 'css', minify: true, target: ['chrome100', 'firefox100', 'safari15'] });
      return { contents: min.code, loader: 'text' };
    });
  },
};

const common = {
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2020', 'chrome90', 'firefox90', 'safari14'],
  legalComments: 'linked',
  charset: 'utf8',
  plugins: [cssText],
  logLevel: 'info',
  define: { 'process.env.NODE_ENV': '"production"' },
};

const builds = [
  { entryPoints: ['src/widget/loader.ts'], outfile: `${out}/widget-loader.js` },
  { entryPoints: ['src/widget/chat.ts'], outfile: `${out}/widget-chat.js` },
  { entryPoints: ['src/admin/index.tsx'], outfile: `${out}/admin.js`, jsx: 'automatic' },
];

const watch = process.argv.includes('--watch');
if (watch) {
  for (const b of builds) (await esbuild.context({ ...common, ...b })).watch();
} else {
  await Promise.all(builds.map((b) => esbuild.build({ ...common, ...b })));
  // Admin stylesheet is a real CSS file (fonts resolved relative to assets/dist).
  await esbuild.build({ entryPoints: ['src/admin/admin.css'], outfile: `${out}/admin.css`, bundle: true, minify: true, external: ['../fonts/*'], target: ['chrome100', 'firefox100', 'safari15'], logLevel: 'info' });
  const manifest = {};
  for (const f of ['widget-loader.js', 'widget-chat.js', 'admin.js', 'admin.css']) {
    const buf = readFileSync(`${out}/${f}`);
    manifest[f] = {
      hash: createHash('sha256').update(buf).digest('hex').slice(0, 12),
      bytes: statSync(`${out}/${f}`).size,
      gzip: gzipSync(buf).length,
    };
  }
  writeFileSync(`${out}/manifest.json`, JSON.stringify(manifest, null, 2) + '\n');
  // Performance budget for the always-loaded bootstrap.
  if (manifest['widget-loader.js'].gzip > 14 * 1024) {
    console.error(`widget-loader.js is ${manifest['widget-loader.js'].gzip} B gzip; budget is 14 KiB`);
    process.exit(1);
  }
  console.log(JSON.stringify(manifest, null, 2));
}
