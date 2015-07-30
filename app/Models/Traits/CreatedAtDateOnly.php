<?php

namespace App\Models\Traits;

trait CreatedAtDateOnly {

    public function getDates() {
        return array_merge($this->dates, [static::CREATED_AT]);
    }

    public function setUpdatedAt($value) { }

}
