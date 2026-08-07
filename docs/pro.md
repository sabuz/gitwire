# GitWire Pro — Roadmap

## Goals

- All Free requirements met
- Graceful degradation when Free is absent or outdated
- Proper license handling (activation, deactivation, expiry)
- Clean upgrade paths (Free → Pro, Pro version upgrades)
- Compatible with Free version upgrades (DB migration safety)

---

## Planned

_Add items as they are scoped._

### Short-term

- [ ] License activation/validation flow
- [ ] Pro upgrade flow UX review
- [ ] Per-user connection scoping UI

### Medium-term

- [ ] Team/organization connection management
- [ ] Webhook-based auto-update triggers

---

## Shipped

| Version | Highlights |
|---|---|
| — | — |

---

## Free/Pro Boundary Rules

- Pro only adds capabilities; it never restricts Free features
- All DB schema additions (Pro columns) use ALTER TABLE — never touch the Free CREATE TABLE
- Pro must gracefully degrade if Free is deactivated or at an incompatible version
- No upsell notices inside Free that violate WordPress.org guidelines (no persistent admin notices, no checkout links in plugin settings)
