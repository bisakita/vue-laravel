<?php

namespace App\Services;

use App\Laravue\Models\User;
use App\Laravue\Models\Role;
use App\Laravue\Models\Permission;
use App\Laravue\JsonResponse;

use App\Http\Resources\PermissionResource;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class UserService
{
    const ITEM_PER_PAGE = 15;

    public function index($searchParams)
    {
        $userQuery = User::query();
        $limit = Arr::get($searchParams, 'limit', static::ITEM_PER_PAGE);
        $role = Arr::get($searchParams, 'role', '');
        $keyword = Arr::get($searchParams, 'keyword', '');

        $userQuery->when(!empty($role), function ($q) use ($role) {
            $q->whereHas('roles', function ($q) use ($role) {
                $q->where('name', $role);
            });
        });

        $userQuery->when(!empty($keyword), function ($q) use ($keyword) {
            $q->where('name', 'LIKE', '%' . $keyword . '%');
            $q->where('email', 'LIKE', '%' . $keyword . '%');
        });
        return $userQuery->paginate($limit);
    }

    public function store($input)
    {
        $input = $this->validateInput($input);
        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);
        $role = Role::findByName($input['role']);
        $user->syncRoles($role);

        return $user;
    }

    public function update($input, $user)
    {
        $this->isUser($user);
        $this->isAdmin($user);
        $this->isAllowedUser($user);

        $input = $this->validateInput($input, false);

        $user->name = $input['name'];
        $user->email = $input['email'];
        if (!empty($input['password']))
            $user->password = $input['password'];
        $user->save();

        return $user;
    }

    public function updatePermissions($permissionIds, $user)
    {
        $this->isUser($user);
        $this->isAdmin($user);
        $this->isAllowedUser($user);

        $rolePermissionIds = array_map(
            function ($permission) {
                return $permission['id'];
            },

            $user->getPermissionsViaRoles()->toArray()
        );

        $newPermissionIds = array_diff($permissionIds, $rolePermissionIds);
        $permissions = Permission::allowed()->whereIn('id', $newPermissionIds)->get();
        $user->syncPermissions($permissions);

        return $user;
    }

    public function destroy(User $user)
    {
        $this->isUser($user);
        $this->isAdmin($user,'No!!, Can\'t delete Admin!');

        try {
            $user->delete();
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 403);
        }

        return response()->json(null, 204);
    }

    public function permissions(User $user)
    {
        try {
            return new JsonResponse([
                'user' => PermissionResource::collection($user->getDirectPermissions()),
                'role' => PermissionResource::collection($user->getPermissionsViaRoles()),
            ]);
        } catch (\Exception $ex) {
            response()->json(['error' => $ex->getMessage()], 403);
        }
    }


    /**
     * @param array $input
     *
     * @return mixed
     */
    public function validateInput($input, $isNew = true)
    {
        if (!empty($input['password_change'])) {
            $input['password'] = Hash::make($input['password_change']);
            unset($input['password_change']);
        } else {
            unset($input['password_change']);
        }
        // $input['is_active'] = (!empty($input['is_active'])) ? 1 : 0;
        return $input;
    }

    /**
     * @param array $user
     *
     * @return mixed
     */
    public function isAllowedUser($user)
    {
        $currentUser = Auth::user();
        if (
            !$currentUser->isAdmin()
            && $currentUser->id !== $user->id
            && !$currentUser->hasPermission(\App\Laravue\Acl::PERMISSION_USER_MANAGE)
        ) {
            return response()->json(['error' => 'Permission denied'], 403);
        }
    }

    /**
     * @param array $user
     *
     * @return mixed
     */
    public function isUser($user)
    {
        if ($user === null)
            return response()->json(['error' => 'User not found'], 404);
    }

    /**
     * @param array $user
     *
     * @return mixed
     */
    public function isAdmin($user, $message = 'Admin can not be modified')
    {
        if ($user->isAdmin())
            return response()->json(['error' => $message], 403);
    }
}
