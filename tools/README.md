# Dev tooling

## PHP CS Fixer (PSR-12)

`php-cs-fixer.phar` is a version-pinned, self-contained copy of
[PHP CS Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) (v3.95.4). It's committed
to the repo so it works with **no Composer and no extra install** — you only need PHP on
your PATH.

The ruleset lives in [`../.php-cs-fixer.dist.php`](../.php-cs-fixer.dist.php): **PSR-12,
non-risky fixers only** (nothing here changes runtime behavior — formatting only).

### Automatic (the normal path)

A shared pre-commit hook (`.githooks/pre-commit`) formats the PHP files you're committing
and re-stages them. You don't run anything. It's activated automatically the first time you
run `npm install` (via the `prepare` script). To activate it manually:

```sh
sh bin/setup-dev-hooks.sh        # or: git config core.hooksPath .githooks
```

The hook is deliberately conservative:

- Only files you're committing are touched — the whole tree converts gradually, not all at once.
- A file that's only **partially** staged (`git add -p`) is **skipped**, so unstaged hunks are
  never pulled into your commit.
- `class.Authorization.php` is always excluded (and unstaged automatically if you stage it).
- If PHP or the PHAR isn't available, the commit proceeds **without** formatting — it never blocks you.

### Manual

```sh
php tools/php-cs-fixer.phar fix                  # format the whole project
php tools/php-cs-fixer.phar fix --dry-run -v     # show what WOULD change, write nothing
php tools/php-cs-fixer.phar fix path/to/File.php # format specific files
```

### Upgrading the pinned version

```sh
curl -sL -o tools/php-cs-fixer.phar \
  https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/vX.Y.Z/php-cs-fixer.phar
chmod +x tools/php-cs-fixer.phar
```

Then bump the version number noted above and in this file.
