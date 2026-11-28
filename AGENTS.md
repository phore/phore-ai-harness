# Agent instructions

## Optional tools

- Add new optional integrations under `src/Tools/<Tool>/`. Keep their adapters,
  helpers and detailed README in that directory; do not couple them to the
  harness core.
- Do not declare tool-specific modules as mandatory dependencies in the root
  `composer.json` or `package.json`. The consuming application installs the
  optional integration's dependencies itself.
- Check optional dependencies when the integration is actually used. If a
  required module or connection is unavailable, throw a clear exception that
  identifies the unavailable integration and the cause. The browser integration
  uses `Tools\Browser\Exception\BrowserUnavailableException` and must clearly
  report that the browser binding is unavailable.
- Do not automatically install dependencies, start browser sessions or register
  optional tool sets during normal harness initialization. Applications opt in
  by constructing and registering the tool set.
- Put setup, API and operational details in the tool's own README. The root
  README and `.ai-usage-info.md` should contain only a short notice and a link
  for optional integrations.
- Preserve existing unrelated tool APIs and files when adding an integration.
