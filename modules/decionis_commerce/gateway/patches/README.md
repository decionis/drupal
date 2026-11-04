# Commerce Fincra compatibility patches

These patches target [Commerce Fincra](https://www.drupal.org/project/commerce_fincra)
on branch `1.0.x` at commit `2011766`. The patched Drupal code and these changes are
distributed under GPL-2.0-or-later; see the module's `LICENSE.txt`.

Apply them in this order:

1. `ApiBaseUrl.patch`: configure the API base URL for a supported local proxy.
2. `HeldSecretKey.patch`: let that proxy hold and inject the payment secret key.
3. `FailedStatus.patch`: treat failed payments as voided authorizations rather than refunds.

The patches were offered in [Commerce Fincra #3588482](https://git.drupalcode.org/project/commerce_fincra/-/work_items/3588482)
and await upstream review. Keep applying them until a supported upstream release
includes the changes. They do not add an ordinary Fincra refund API. The module README explains Composer patch
installation, provider limitations and the optional AgentSafe host setup.
