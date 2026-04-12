<?php

namespace App\Security\Permission;

final class TenantPermission
{


  public const ACCESS      = 'ENTITE_ACCESS';
  public const ADMIN       = 'ENTITE_ADMIN';
  public const EMPLOYE     = 'ENTITE_EMPLOYE';

  public const UTILISATEUR_MANAGE = 'UTILISATEUR_MANAGE';
  public const ENGIN_MANAGE = 'ENGIN_MANAGE';
  public const ADMIN_DASHBOARD_MANAGE = 'ENTITE_ADMIN_DASHBOARD_MANAGE';
  public const USERS_MANAGE = 'ENTITE_USERS_MANAGE';

  /**
   * ✅ Liste exhaustive des permissions "tenant" gérées par TenantVoter.
   * Utilise ça dans supports():
   *   return $subject instanceof Entite && in_array($attribute, TenantPermission::ALL, true);
   */
  public const ALL = [

    self::ACCESS,
    self::ADMIN,
    self::EMPLOYE,

    self::UTILISATEUR_MANAGE,
    self::ENGIN_MANAGE,
    self::ADMIN_DASHBOARD_MANAGE,
    self::USERS_MANAGE,
  ];
}
