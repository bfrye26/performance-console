import { mkdirSync, copyFileSync } from 'node:fs';

mkdirSync('assets/vendor/web-vitals', { recursive: true });
for (const [source, target] of [['dist/web-vitals.iife.js', 'web-vitals.iife.js'], ['LICENSE', 'LICENSE']]) {
    copyFileSync(`node_modules/web-vitals/${source}`, `assets/vendor/web-vitals/${target}`);
}
