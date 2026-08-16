# SLOP-8: Adopt the upstream plugin system for third-party rule packs

- **Ticket:** SLOP-8
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** engine
- **Created:** 2026-08-16T08:07:29+00:00
- **Updated:** 2026-08-16T08:08:08+00:00
- **Summary:** Upstream ships definePlugin/defineConfig, an apiVersion, config extends/plugins and Registry::registerPlugin; the PHP Registry only accepts first-party objects.
- **Next:** Decide whether third-party rule packs are a goal for this fork before designing the loader.
- **Validation:** composer run test && composer run analyse
- **Priority:** 1
- **Wave:** 4
- **Format version:** 1

## Agent Task Brief
Upstream src/plugin.ts (38 lines) defines SlopScanPlugin with meta.apiVersion, optional rules and configs maps, plus definePlugin/defineConfig helpers; ConfigFile adds 'extends' and 'plugins'. src/cli.ts loads them and calls registry.registerPlugin(namespace, plugin), and report-metadata.ts folds plugin namespace/name/version/source into the report and the config hash so a delta cannot silently compare across different plugin sets. The PHP Registry only accepts first-party LanguagePlugin/FactProvider/RulePlugin/ReporterPlugin objects and Config has no extends or plugins key. Largest structural gap: it is the difference between a tool and a platform, so it needs a product decision first.
