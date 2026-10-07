# CMS Mobile development

Read README.md and MOBILE-PUSH.md before changing this package. Follow the
active shared-vendor skeeks-cms-package-development skill.

This package owns mobile installations, native bridges, provider transports,
delivery outcomes and profile push controls. CMS owns login credentials,
revocable sessions and the base devices UI. Never introduce a dependency from
CMS to cms-mobile or add mobile tables to a CMS core migration.

Subscribe to the CMS UserSessions events; session cleanup runs in the same DB
transaction. External sends run only through cms-job. Keep owner, realm,
generation and current-session checks before delivery. Provider acceptance
does not prove receipt; unknown outcomes must not be automatically resent.

The native API has a versioned route. Keep installed clients compatible and
do not claim iOS support until its adapter and physical-device delivery work.
Keep server credentials out of the repository, browser and application bundle.
Never adopt an unknown pre-existing schema based on column names alone.
Unreleased local prototype reconciliation belongs to a guarded project script.

Run isolated tests and PHP/JS syntax checks. No external sends, release,
commit, push or production deployment are implied by a local code change.
