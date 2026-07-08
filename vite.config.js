import { createAppConfig } from "@nextcloud/vite-config";
import browserslistToEsbuild from "browserslist-to-esbuild";
import { join, resolve } from "path";
import eslint from "vite-plugin-eslint";
import stylelint from "vite-plugin-stylelint";

const isProduction = process.env.NODE_ENV === "production";
const buildTargets = browserslistToEsbuild().filter((target) => target !== "ios11");

export default createAppConfig(
  {
    main: resolve(join('src', 'main.js')),
    personal: resolve(join('src', 'personal.js')),
    admin: resolve(join('src', 'admin.js')),
    'bot-picker': resolve(join('src', 'bot-picker.js')),
  },
  {
    config: {
      css: { modules: { localsConvention: 'camelCase' } },
      esbuild: { target: buildTargets },
      build: {
        target: buildTargets,
        cssTarget: buildTargets,
      },
      plugins: [eslint(), stylelint()],
    },
    inlineCSS: { relativeCSSInjection: true },
    minify: isProduction,
    createEmptyCSSEntryPoints: true,
    extractLicenseInformation: true,
    thirdPartyLicense: false,
  }
);
