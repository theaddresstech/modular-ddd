<?php

declare(strict_types=1);

namespace LaravelModularDDD\CQRS\Security;

use LaravelModularDDD\CQRS\Contracts\CommandInterface;
use LaravelModularDDD\CQRS\Contracts\QueryInterface;
use LaravelModularDDD\CQRS\Exceptions\UnauthorizedCommandException;
use LaravelModularDDD\CQRS\Exceptions\UnauthorizedQueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CommandAuthorizationManager
{
    private array $commandPolicies = [];
    private array $queryPolicies = [];
    private array $rolePermissions = [];
    private bool $strictMode = true;

    public function __construct(bool $strictMode = true)
    {
        $this->strictMode = $strictMode;
        $this->loadDefaultPolicies();
    }

    /**
     * Authorize a command execution
     */
    public function authorizeCommand(CommandInterface $command, $user = null): void
    {
        $user = $user ?? Auth::user();
        $commandClass = get_class($command);

        // Skip authorization for guest users in non-strict mode
        if (!$user && !$this->strictMode) {
            Log::info('Command authorization skipped (non-strict mode)', [
                'command' => $commandClass,
                'user' => 'guest',
            ]);
            return;
        }

        // Check if user is authenticated
        if (!$user) {
            throw new UnauthorizedCommandException("Authentication required for command: {$commandClass}");
        }

        // Check command-specific policy
        if (isset($this->commandPolicies[$commandClass])) {
            $policy = $this->commandPolicies[$commandClass];

            if (!$this->evaluatePolicy($policy, $command, $user)) {
                throw new UnauthorizedCommandException(
                    "Insufficient permissions for command: {$commandClass}",
                    $user->id ?? null
                );
            }
        } elseif ($this->strictMode) {
            // In strict mode, commands must have explicit authorization
            throw new UnauthorizedCommandException(
                "No authorization policy defined for command: {$commandClass}"
            );
        }

        Log::info('Command authorized', [
            'command' => $commandClass,
            'user_id' => $user->id ?? null,
            'permissions_checked' => isset($this->commandPolicies[$commandClass]),
        ]);
    }

    /**
     * Authorize a query execution
     */
    public function authorizeQuery(QueryInterface $query, $user = null): void
    {
        $user = $user ?? Auth::user();
        $queryClass = get_class($query);

        // Queries are generally less restricted than commands
        if (!$user && !$this->strictMode) {
            Log::info('Query authorization skipped (non-strict mode)', [
                'query' => $queryClass,
                'user' => 'guest',
            ]);
            return;
        }

        // Check query-specific policy
        if (isset($this->queryPolicies[$queryClass])) {
            $policy = $this->queryPolicies[$queryClass];

            if (!$this->evaluatePolicy($policy, $query, $user)) {
                throw new UnauthorizedQueryException(
                    "Insufficient permissions for query: {$queryClass}",
                    $user->id ?? null
                );
            }
        }

        Log::debug('Query authorized', [
            'query' => $queryClass,
            'user_id' => $user->id ?? null,
            'permissions_checked' => isset($this->queryPolicies[$queryClass]),
        ]);
    }

    /**
     * Register authorization policy for a command
     */
    public function registerCommandPolicy(string $commandClass, array $policy): void
    {
        $this->commandPolicies[$commandClass] = $policy;

        Log::info('Command policy registered', [
            'command' => $commandClass,
            'policy' => $policy,
        ]);
    }

    /**
     * Register authorization policy for a query
     */
    public function registerQueryPolicy(string $queryClass, array $policy): void
    {
        $this->queryPolicies[$queryClass] = $policy;

        Log::debug('Query policy registered', [
            'query' => $queryClass,
            'policy' => $policy,
        ]);
    }

    /**
     * Define role-based permissions
     */
    public function defineRolePermissions(string $role, array $permissions): void
    {
        $this->rolePermissions[$role] = $permissions;

        Log::info('Role permissions defined', [
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Check if user has specific permission
     */
    public function hasPermission($user, string $permission): bool
    {
        if (!$user) {
            return false;
        }

        // Check direct user permissions (if user model supports it)
        if (method_exists($user, 'hasPermission') && $user->hasPermission($permission)) {
            return true;
        }

        // Check role-based permissions
        if (method_exists($user, 'getRoles')) {
            $userRoles = $user->getRoles();
            foreach ($userRoles as $role) {
                if (isset($this->rolePermissions[$role]) && in_array($permission, $this->rolePermissions[$role])) {
                    return true;
                }
            }
        }

        // Fallback: check if user has a role property
        if (isset($user->role) && isset($this->rolePermissions[$user->role])) {
            return in_array($permission, $this->rolePermissions[$user->role]);
        }

        return false;
    }

    /**
     * Evaluate authorization policy
     */
    private function evaluatePolicy(array $policy, $command, $user): bool
    {
        // Check required permissions
        if (isset($policy['permissions'])) {
            foreach ($policy['permissions'] as $permission) {
                if (!$this->hasPermission($user, $permission)) {
                    return false;
                }
            }
        }

        // Check required roles
        if (isset($policy['roles'])) {
            $userRoles = $this->getUserRoles($user);
            $hasRequiredRole = false;

            foreach ($policy['roles'] as $role) {
                if (in_array($role, $userRoles)) {
                    $hasRequiredRole = true;
                    break;
                }
            }

            if (!$hasRequiredRole) {
                return false;
            }
        }

        // Check ownership (if command/query supports it)
        if (isset($policy['ownership']) && $policy['ownership']) {
            if (method_exists($command, 'getOwnerId')) {
                $ownerId = $command->getOwnerId();
                if ($ownerId && $ownerId !== ($user->id ?? null)) {
                    return false;
                }
            }
        }

        // Check custom conditions
        if (isset($policy['conditions']) && is_callable($policy['conditions'])) {
            return $policy['conditions']($command, $user);
        }

        return true;
    }

    /**
     * Get user roles
     */
    private function getUserRoles($user): array
    {
        if (!$user) {
            return [];
        }

        if (method_exists($user, 'getRoles')) {
            return $user->getRoles();
        }

        if (isset($user->roles) && is_array($user->roles)) {
            return $user->roles;
        }

        if (isset($user->role)) {
            return [$user->role];
        }

        return [];
    }

    /**
     * Load default authorization policies
     */
    private function loadDefaultPolicies(): void
    {
        // Define default role permissions
        $this->defineRolePermissions('admin', [
            'command.execute.any',
            'query.execute.any',
            'aggregate.create',
            'aggregate.update',
            'aggregate.delete',
            'system.configure',
        ]);

        $this->defineRolePermissions('user', [
            'query.execute.own',
            'aggregate.create.own',
            'aggregate.update.own',
            'aggregate.view.own',
        ]);

        $this->defineRolePermissions('guest', [
            'query.execute.public',
            'aggregate.view.public',
        ]);
    }

    /**
     * Get authorization statistics
     */
    public function getStatistics(): array
    {
        return [
            'command_policies_registered' => count($this->commandPolicies),
            'query_policies_registered' => count($this->queryPolicies),
            'role_permissions_defined' => count($this->rolePermissions),
            'strict_mode_enabled' => $this->strictMode,
            'available_roles' => array_keys($this->rolePermissions),
        ];
    }
}