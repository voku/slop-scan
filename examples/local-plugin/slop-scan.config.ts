import { defineConfig } from "slop-scan";
import localPlugin from "./contains-word-plugin.mjs";

export default defineConfig({
  plugins: {
    local: localPlugin,
  },
  extends: ["plugin:local/recommended"],
  rules: {
    "local/contains-word": {
      options: { word: "danger" },
    },
  },
});
