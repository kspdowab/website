<?php
/**
 * KSPDOWA — Role-Based Access Control (RBAC)
 * ============================================================
 * Implements the RBAC model defined in 06_ROLES_PERMISSIONS_MATRIX.md.
 *
 * Tables used: roles, permissions, role_permissions, user_roles
 *
 * IMPORTANT RULES (from spec):
 *   - Do NOT use a single is_admin flag — use RBAC tables.
 *   - A member can view only their own records.
 *   - Taluk officers are scoped to their own Taluk.
 *   - District officers are scoped to their own District.
 *   - State access is permission-controlled.
 *   - Authorization is always enforced server-side.
 *
 * Permission string format: "module.action" (e.g. "grievances.forward")
 *
 * Usage:
 *   RBAC::requirePermission($userId, 'grievances', 'forward');
 *   RBAC::hasRole($userId, 'State Super Admin');
 *   RBAC::getUserAssociationUnit($userId); // for scope enforcement
 * ============================================================
 */

declare(strict_types=1);

class RBAC
{
    // Request-level caches (cleared per request automatically)
    /** @var array<int, list<array{name:string,scope_type:string,association_unit_id:int|null}>> */
    private static array $rolesCache = [];

    /** @var array<int, list<string>> */
    private static array $permissionsCache = [];

    // ------------------------------------------------------------------
    // Role queries
    // ------------------------------------------------------------------

    /**
     * Return all role rows for a user.
     * Each row: {name, scope_type, association_unit_id}
     */
    public static function getUserRoles(int $userId): array
    {
        if (isset(self::$rolesCache[$userId])) {
            return self::$rolesCache[$userId];
        }

        $rows = Database::fetchAll(
            'SELECT r.name, r.scope_type, ur.association_unit_id
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?
               AND r.status = ?',
            [$userId, 'active']
        );

        self::$rolesCache[$userId] = $rows;
        return $rows;
    }

    /**
     * Return flat list of role names for a user.
     */
    public static function getUserRoleNames(int $userId): array
    {
        return array_column(self::getUserRoles($userId), 'name');
    }

    /**
     * Check whether a user holds any of the given role name(s).
     *
     * @param string|string[] $roleNames
     */
    public static function hasRole(int $userId, string|array $roleNames): bool
    {
        $check      = is_array($roleNames) ? $roleNames : [$roleNames];
        $userRoles  = self::getUserRoleNames($userId);
        return count(array_intersect($check, $userRoles)) > 0;
    }

    // ------------------------------------------------------------------
    // Permission queries
    // ------------------------------------------------------------------

    /**
     * Return all permission strings ("module.action") for a user.
     */
    public static function getUserPermissions(int $userId): array
    {
        if (isset(self::$permissionsCache[$userId])) {
            return self::$permissionsCache[$userId];
        }

        $rows = Database::fetchAll(
            'SELECT DISTINCT p.module, p.action
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?
               AND r.status = ?
               AND p.status = ?',
            [$userId, 'active', 'active']
        );

        $permissions = [];
        foreach ($rows as $row) {
            $permissions[] = $row['module'] . '.' . $row['action'];
        }

        self::$permissionsCache[$userId] = $permissions;
        return $permissions;
    }

    /**
     * Check whether a user has a specific permission.
     */
    public static function hasPermission(int $userId, string $module, string $action): bool
    {
        return in_array($module . '.' . $action, self::getUserPermissions($userId), true);
    }

    /**
     * Check whether a user has a specific permission or is a State Super Admin.
     */
    public static function can(int $userId, string $module, string $action = 'view'): bool
    {
        if (self::hasRole($userId, 'State Super Admin')) {
            return true;
        }
        return self::hasPermission($userId, $module, $action);
    }

    // ------------------------------------------------------------------
    // Enforcement (abort on failure)
    // ------------------------------------------------------------------

    /**
     * Require a specific permission. Aborts with HTTP 403 if not held.
     */
    public static function requirePermission(int $userId, string $module, string $action): void
    {
        if (!self::hasPermission($userId, $module, $action)) {
            AuditLogger::log('ACCESS_DENIED', $module, null, null, [
                'action'  => $action,
                'user_id' => $userId,
                'uri'     => $_SERVER['REQUEST_URI'] ?? '',
            ]);

            ErrorHandler::abort(403, 'You do not have permission to perform this action.');
        }
    }

    /**
     * Require any of the given role(s). Aborts with HTTP 403 if not held.
     *
     * @param string|string[] $roleNames
     */
    public static function requireRole(int $userId, string|array $roleNames): void
    {
        if (!self::hasRole($userId, $roleNames)) {
            AuditLogger::log('ACCESS_DENIED', 'roles', null, null, [
                'required_role' => $roleNames,
                'user_id'       => $userId,
            ]);

            ErrorHandler::abort(403, 'You do not have the required role for this area.');
        }
    }

    // ------------------------------------------------------------------
    // Scope / geographic restriction helpers
    // ------------------------------------------------------------------

    /**
     * Return the association_unit_id bound to the user's (first) role.
     * Used by district/taluk officers to enforce geographic scoping.
     * Returns null for state-scoped or member roles.
     */
    public static function getUserAssociationUnit(int $userId): ?int
    {
        $roles = self::getUserRoles($userId);
        foreach ($roles as $role) {
            if ($role['association_unit_id'] !== null) {
                return (int) $role['association_unit_id'];
            }
        }
        return null;
    }

    /**
     * Return the scope_type of the user's most privileged role.
     * Precedence: state > district > taluk > member
     */
    public static function getHighestScopeType(int $userId): string
    {
        $precedence = ['state' => 4, 'district' => 3, 'taluk' => 2, 'member' => 1];
        $highest    = 'member';
        $highScore  = 0;

        foreach (self::getUserRoles($userId) as $role) {
            $score = $precedence[$role['scope_type']] ?? 0;
            if ($score > $highScore) {
                $highScore = $score;
                $highest   = $role['scope_type'];
            }
        }

        return $highest;
    }

    // ------------------------------------------------------------------
    // Cache management
    // ------------------------------------------------------------------

    /**
     * Clear cached roles/permissions for a user.
     * Must be called after any role or permission change.
     */
    public static function clearCache(int $userId): void
    {
        unset(self::$rolesCache[$userId], self::$permissionsCache[$userId]);
    }

    /**
     * Clear the entire request-level cache.
     */
    public static function clearAllCache(): void
    {
        self::$rolesCache       = [];
        self::$permissionsCache = [];
    }
}

if (!function_exists('admin_can')) {
    /**
     * Helper to check RBAC module permission, granting full access to State Super Admin.
     */
    function admin_can(int $userId, string $module, string $action = 'view'): bool {
        return RBAC::can($userId, $module, $action);
    }
}

