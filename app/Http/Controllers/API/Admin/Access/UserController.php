<?php

namespace App\Http\Controllers\API\Admin\Access;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\Access\PermissionResource;
use App\Http\Resources\Admin\Access\UserResource;
use App\Models\Access\Role\Role;
use App\Models\Access\User\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('admin:super')->only('destroy');
        $this->middleware('admin')->except('index', 'updateProfile');
    }

    /**
     * Display a listing of the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Http\Resources\Admin\Access\UserResource
     */
    public function index(Request $request)
    {
        $role = trim($request->role);
        $search = trim($request->search);

        $users = User::where('id', '!=', $request->user()->id)
            ->whereHas('roles', function ($query) {
                $query->where('id', '!=', 1);
            });

        if (!empty($role)) {
            $users->whereHas('roles', function ($query) use ($role) {
                $query->where('name', $role);
            });
        }

        if (!empty($search)) {
            $users->where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%");
            });
        }

        return UserResource::collection($users->paginate());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Http\Resources\Admin\Access\UserResource
     */
    public function store(Request $request)
    {
        $request->validate([
            'role' => 'required|string|exists:roles,name,id,!1',
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $request['name'],
            'email' => $request['email'],
            'password' => Hash::make($request['password']),
        ]);

        $role = Role::findByName($request['role']);
        $user->assignRole($role);

        return new UserResource($user);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Access\User\User  $user
     * @return \Illuminate\Http\Response
     */
    public function show(User $user)
    {
        return new UserResource($user);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Access\User\User  $user
     * @return \App\Http\Resources\Admin\Access\UserResource
     */
    public function update(Request $request, User $user)
    {
        if ($user->isAdmin('super')) return response()->json(['message' => 'Permission denied'], 403);

        $request->validate([
            'role' => 'required|string|exists:roles,name,id,!1',
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user)],
        ]);

        $user->update([
            'name' => $request['name'],
            'email' => $request['email'],
        ]);

        if ($request->password) {
            if (!$request->user()->isAdmin('super'))
                return response()->json(['message' => 'Permission denied'], 403);

            $request->validate([
                'password' => 'required|string|min:8',
            ]);

            $user->update([
                'password' => Hash::make($request['password']),
            ]);
        }

        $role = Role::findByName($request['role']);
        $user->syncRoles($role);

        return new UserResource($user);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Access\User\User  $user
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user)
    {
        if ($user->isAdmin('super')) return response()->json(['message' => 'Permission denied'], 403);

        $user->delete();

        return response()->noContent();
    }

    /**
     * Get all user permissions
     *
     * @param  \App\Models\Access\User\User  $user
     * @return \Illuminate\Http\Resources\Json\JsonResource
     */
    public function permissions(User $user)
    {
        return new JsonResource([
            'user' => PermissionResource::collection($user->getDirectPermissions()),
            'role' => PermissionResource::collection($user->getPermissionsViaRoles()),
        ]);
    }

    /**
     * Update user direct permissions
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Access\User\User  $user
     * @return \App\Http\Resources\Admin\Access\UserResource
     */
    public function updatePermissions(Request $request, User $user)
    {
        if ($user->isAdmin()) return response()->json(['message' => 'Permission denied'], 403);

        $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name'
        ]);

        $user->syncPermissions($request['permissions']);
        return new UserResource($user);
    }

    /**
     * Update current user profile
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Http\Resources\Admin\Access\UserResource
     */
    public function updateProfile(Request $request)
    {
        $auth = $request->user()->id === $request->id;
        if ($auth) {
            $user = User::find($request->id);

            $request->validate([
                'name' => 'required|string|max:255',
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            ]);

            $user->update([
                'name' => $request['name'],
                'email' => $request['email'],
            ]);

            if ($request->password) {
                $request->validate([
                    'password' => 'required|string|min:8',
                ]);

                $user->update([
                    'password' => Hash::make($request['password']),
                ]);
            }

            return new UserResource($user);
        }
    }
}