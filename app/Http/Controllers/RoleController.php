<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;

/**
 * Read-only role catalog lookup. No RolePolicy exists (Role has no
 * create/update/delete route - it's a fixed, tenant-seeded catalog per
 * §11, see Role's own docblock), so this is gated inline rather than via
 * authorize(). Currently only the Approval Limits admin screen consumes
 * this, but role names/labels carry no sensitive data, so it's open to
 * any authenticated user rather than admin-only.
 */
class RoleController extends Controller
{
    public function index(Request $request)
    {
        return Role::where('tenant_id', $request->user()->tenant_id)->orderBy('label')->get(['id', 'name', 'label']);
    }
}
