# EXT:form_permissions

[![TYPO3 14](https://img.shields.io/badge/TYPO3-14-orange.svg?style=flat-square&logo=typo3)](https://get.typo3.org/14)
[![Latest Stable Version](https://img.shields.io/packagist/v/brosua/form-permissions?style=flat-square)](https://packagist.org/packages/brosua/form-permissions)
[![License](https://img.shields.io/packagist/l/brosua/form-permissions?style=flat-square)](https://packagist.org/packages/brosua/form-permissions)
[![TER](https://img.shields.io/badge/TER-form__permissions-green?style=flat-square)](https://extensions.typo3.org/extension/form_permissions)

Extends TYPO3's built-in form framework with folder-based storage organisation and fine-grained backend user permissions.

## What this extension does

Out of the box, `EXT:form` stores all database-backed form definitions at the root level (PID 0) and exposes them to every backend user who has access to the table. This extension replaces that storage adapter with a permission-aware version that:

- **Organises forms in pages** – any page with the *Contains Plugin* field set to **Forms** becomes a valid storage location.
- **Respects web mounts and page permissions** – editors can only see and edit forms that live on pages within their web mount and for which they have at least `PAGE_SHOW` access.
- **Restricts table access** – both `tables_select` and `tables_modify` rights for the `form_definition` table are enforced.

## Installation

Install this extension via `composer req brosua/form-permissions`.

## Configuration

### 1. Mark a page as a form storage location

Open any page in the backend and go to **Appearance → Contains Plugin**. Select **Forms** from the dropdown. The page will immediately receive a dedicated icon in the page tree and appear as a selectable storage location in the form manager.

> **Root (PID 0)** is also accepted as a storage location for core compatibility.

### 2. Backend user group permissions

Grant the editing group the following rights in the backend user group record:

| Setting | Value |
|---|---|
| **Tables (listing)** | `Form Definition (form_definition)` |
| **Tables (modify)** | `Form Definition (form_definition)` |
| **Web mounts** | Include all pages that serve as form storage locations |
| **Page permissions** | At minimum `Show page` on every storage page |

Without `tables_select` the user cannot see any forms. Without `tables_modify` the form manager's *New Form* button is hidden.
