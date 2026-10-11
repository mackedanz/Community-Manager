// Baut public/css/app.css aus assets/app.css (Tailwind v4, scannt views/ und public/js/).
// Nutzung: node build-css.mjs   (benötigt tailwindcss, @tailwindcss/postcss und postcss in node_modules)
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import postcss from "postcss";
import tailwind from "@tailwindcss/postcss";

const root = dirname(fileURLToPath(import.meta.url));
const from = resolve(root, "assets/app.css");
const css = await readFile(from, "utf8");
const out = await postcss([tailwind({ base: root, optimize: { minify: true } })]).process(css, { from });
await mkdir(resolve(root, "public/css"), { recursive: true });
await writeFile(resolve(root, "public/css/app.css"), out.css);
console.log(`public/css/app.css: ${(out.css.length / 1024).toFixed(1)} KB`);
