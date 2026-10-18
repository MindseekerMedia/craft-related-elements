# Release Notes for Related Elements

## 1.3.1 - 2026-10-18

### Fixed

- Fix incoming relationship queries failing on PostgreSQL due to an unquoted recursive CTE name (#28).

## 1.3.0 - 2026-10-07

### Added

- Independent server-side pagination for the References and Referenced by sections.
- Global relation-type filters.
- Entry-type scoping via plugin settings or `enabledEntryTypeUids` configuration.
- Section scoping via plugin settings or `enabledSectionUids` configuration.

### Fixed

- Prevent memory exhaustion when elements have thousands of relationships.
- Include nested CKEditor and Matrix entries even when they contain no relational fields.
- Resolve incoming CKEditor, Matrix, and Neo relationships through all nesting levels and shared owners.
- Keep nested field labels on the ownership path of the element being edited.
- Resolve nested Content Block relationships in both directions and include their field labels.
- Exclude deleted containers and historical draft/revision sources from incoming relationships.
- Keep pagination available when the first result page contains only inaccessible elements.
- Initialize native chip action menus and scripts for paginated results.
- Exclude canonical self-references when editing drafts.
- Avoid retaining detached sidebar selections in window event listeners.
- Return empty relationship lists for unsaved elements.
- Match custom entry-type icon colors to Craft's control panel palette.

## 1.2.0 - 2025-05-29

### Added

- "Show More" functionality to limit visible related elements with configurable initial limit.
- New `initialLimit` setting to control how many elements are shown initially (default: 10).
- New `showElementTypeLabel` setting to control whether element type labels (Entry, Category, Asset, Tag) are displayed next to each related element (default: true).
- Support for CKEditor field related entries.
- Custom icon and color support for entry types.

### Changed

- Related elements are now organized based on relationship direction.

## 1.1.4 - 2025-04-28

### Fixed

- Error handling for related elements without fieldLayout.

## 1.1.3 - 2025-04-09

### Added

- Improve error handling.

### Fixed

- Adjust element sidebar padding.
- Typo in settings instructions.

## 1.1.2 - 2025-01-13

### Fixed

- Prefer related elements from the current site.

## 1.1.1 - 2025-01-05

### Fixed

- Merge nested Neo & Matrix elements if multiple blocks are present.
- Recursively relate nested fields.
- Show related entries that don't exist in the primary site.

## 1.1.0 - 2024-10-06

### Added

- Support for nested elements in Matrix and Neo fields.

## 1.0.0 - 2024-09-17

### Added

- Initial release.
