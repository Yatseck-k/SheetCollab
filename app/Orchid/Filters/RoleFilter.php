<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Orchid\Filters\Filter;
use Orchid\Platform\Models\Role;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Select;

class RoleFilter extends Filter
{
    /**
     * The displayable name of the filter.
     */
    #[\Override]
    public function name(): string
    {
        return __('Roles');
    }

    /**
     * The array of matched parameters.
     *
     * @return array<int, string>
     */
    #[\Override]
    public function parameters(): array
    {
        return ['role'];
    }

    /**
     * Apply to a given Eloquent query builder.
     *
     * @param  Builder<Model>  $builder
     * @return Builder<Model>
     */
    public function run(Builder $builder): Builder
    {
        return $builder->whereHas('roles', function (Builder $query): void {
            $query->where('slug', $this->request->get('role'));
        });
    }

    /**
     * Get the display fields.
     *
     * @return array<int, Field>
     */
    #[\Override]
    public function display(): array
    {
        return [
            Select::make('role')
                ->fromModel(Role::class, 'name', 'slug')
                ->empty()
                ->value($this->request->get('role'))
                ->title(__('Roles')),
        ];
    }

    /**
     * Value to be displayed
     */
    #[\Override]
    public function value(): string
    {
        return $this->name().': '.Role::where('slug', $this->request->get('role'))->first()->name;
    }
}
