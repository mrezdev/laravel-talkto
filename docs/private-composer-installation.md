# Private Composer Installation

P.49A does not modify host root Composer files. These examples are placeholders for a later migration phase.

## Local Path Repository

For local development while the package lives inside a host repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/laravel-talkto",
      "options": {
        "symlink": false
      }
    }
  ],
  "require": {
    "<vendor>/<package>": "*"
  }
}
```

## Private VCS Repository

After the owner creates a private repository and tags a release:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "<github-private-repo-url>"
    }
  ],
  "require": {
    "<vendor>/<package>": "<version-tag>"
  }
}
```

Use placeholders only in docs. Do not commit real credentials, private access values, production URLs, or personal authentication material.

## Migration Notes

- Keep host applications on the path repository until a later phase approves the switch.
- Test the VCS strategy in a non-production branch first.
- Confirm package CI passes before updating host dependency references.
- Keep rollback simple by preserving the path repository option until the private tag is proven in both host applications.
