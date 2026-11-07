<?php

namespace App\Support;

use App\Enums\Role;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * How the API documentation (Scramble, /docs/api and docs/api/openapi.json,
 * v6 D38) describes what every endpoint has in common: the bearer token and
 * the X-Role header.
 */
final class ApiDocs
{
    public static function configure(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(fn (OpenApi $openApi) => $openApi->secure(SecurityScheme::http('bearer')))
            ->withOperationTransformers(function (Operation $operation, RouteInfo $route): void {
                if (! in_array('auth:sanctum', $route->route->gatherMiddleware(), true)) {
                    return;
                }

                $roles = new StringType;
                $roles->enum(array_map(fn (Role $role) => $role->value, Role::cases()));

                $operation->addParameters([
                    Parameter::make('X-Role', 'header')
                        ->setSchema(Schema::fromType($roles))
                        ->required(false)
                        ->description('The role to act in, one of the user\'s `roles`. Needed when the user holds more than one; a role the user does not hold gives 403 `role_not_held`.'),
                ]);
            });
    }
}
