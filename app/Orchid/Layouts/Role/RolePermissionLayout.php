<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Role;

use App\Models\User;
use Illuminate\Support\Collection;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Layouts\Rows;

class RolePermissionLayout extends Rows
{
    /**
     * @var User|null
     */
    private $user;

    /**
     * The screen's layout elements.
     *
     *
     * @return Field[]
     *
     * @throws \Throwable
     */
    public function fields(): array
    {
        $this->user = $this->query->get('user');

        return $this->generatedPermissionFields(
            $this->query->getContent('permission')
        );
    }

    /**
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $permissionsRaw
     * @return array<int, mixed>
     */
    private function generatedPermissionFields(Collection $permissionsRaw): array
    {
        return $permissionsRaw
            ->map(fn (Collection $permissions, string $title): Collection => $this->makeCheckBoxGroup($permissions, $title))
            ->flatten()
            ->toArray();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $permissions
     * @return Collection<int, mixed>
     */
    private function makeCheckBoxGroup(Collection $permissions, string $title): Collection
    {
        return $permissions
            ->map(fn (array $chunks): CheckBox => $this->makeCheckBox(collect($chunks)))
            ->flatten()
            ->map(fn (CheckBox $checkbox, $key) => $key === 0
                ? $checkbox->title($title)
                : $checkbox)
            ->chunk(4)
            ->map(fn (Collection $checkboxes) => Group::make($checkboxes->toArray())
                ->alignEnd()
                ->autoWidth());
    }

    /** @param Collection<string, mixed> $chunks */
    private function makeCheckBox(Collection $chunks): CheckBox
    {
        return CheckBox::make('permissions.'.base64_encode((string) $chunks->get('slug')))
            ->placeholder($chunks->get('description'))
            ->value($chunks->get('active'))
            ->sendTrueOrFalse()
            ->indeterminate($this->getIndeterminateStatus(
                $chunks->get('slug'),
                $chunks->get('active')
            ));
    }

    private function getIndeterminateStatus(string $slug, bool $value): bool
    {
        return optional($this->user)->hasAccess($slug) === true && $value === false;
    }
}
