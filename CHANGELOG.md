# Changelog
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## 2.0.0 - unreleased

  * **BREAKING**: Upgrade to phpseclib 4. All `phpseclib3\` types become `phpseclib4\`, including the return type of `PublicKeyAuthentication::getPrivateKey()` and the key parameters of `PublicKeyHelper`. Projects pinned to `phpseclib/phpseclib` ^3.0 must update first.

  * Loading an encrypted private key without a passphrase now surfaces `phpseclib4\Exception\PasswordNeededException` instead of a generic key-load failure

## 1.0.0 - 2026-05-11

  * Initial release