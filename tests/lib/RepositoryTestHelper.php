<?php

use Illuminate\Database\Eloquent\Model;
use \PHPUnit_Framework_Assert as PHPUnit;

class RepositoryTestHelper  {

    var $use_uuid = true;

    function __construct($create_model_fn, $repository) {
        $this->create_model_fn = $create_model_fn;
        $this->repository = $repository;
    }

    public function cleanup() {
        foreach($this->repository->findAll() as $model) {
            $this->repository->delete($model);
        }

        return $this;
    }

    public function testLoad() {
        $created_model = $this->newModel();
        $loaded_model = $this->repository->findByID($created_model['id']);
        PHPUnit::assertNotEmpty($loaded_model);

        PHPUnit::assertEquals($created_model->toArray(), normalize_updated_date($loaded_model->toArray(), $created_model->toArray()));

        return $loaded_model;
    }

    public function testUpdate($update_attributes) {
        $created_model = $this->newModel();

        // update by ID
        $this->repository->update($created_model, $update_attributes);

        // load from repo again and test
        if ($this->use_uuid) {
            $loaded_model = $this->repository->findByUuid($created_model['uuid']);
        } else {
            $loaded_model = $this->repository->findByID($created_model['id']);
        }

        PHPUnit::assertNotEmpty($loaded_model);
        foreach($update_attributes as $k => $v) {
            PHPUnit::assertEquals($v, $loaded_model[$k]);
        }

        // update by UUID
        if ($this->use_uuid) {
            $this->repository->updateByUuid($created_model['uuid'], $update_attributes);
        } else {
            $this->repository->update($created_model, $update_attributes);
        }

        // load from repo again
        if ($this->use_uuid) {
            $loaded_model = $this->repository->findByUuid($created_model['uuid']);
        } else {
            $loaded_model = $this->repository->findByID($created_model['id']);
        }

        PHPUnit::assertNotEmpty($loaded_model);
        foreach($update_attributes as $k => $v) {
            PHPUnit::assertEquals($v, $loaded_model[$k]);
        }

        // clean up
        return $created_model;
    }

    public function testDelete() {
        $created_model = $this->newModel();

        // delete by ID
        PHPUnit::assertTrue($this->repository->delete($created_model));

        // load from repo
        $loaded_model = $this->repository->findByID($created_model['id']);
        PHPUnit::assertEmpty($loaded_model);


        // create another one
        $created_model = $this->newModel();

        // delete by uuid
        if ($this->use_uuid) {
            $this->repository->deleteByUuid($created_model['uuid']);
        } else {
            $this->repository->delete($created_model);
        }

        // load from repo
        if ($this->use_uuid) {
            $loaded_model = $this->repository->findByUuid($created_model['uuid']);
        } else {
            $loaded_model = $this->repository->findByID($created_model['id']);
        }
        PHPUnit::assertEmpty($loaded_model);

    }


    public function testFindAll() {
        $created_model = $this->newModel();
        $created_model_2 = $this->newModel();
        $loaded_models = array_values(iterator_to_array($this->repository->findAll()));
        PHPUnit::assertNotEmpty($loaded_models);
        PHPUnit::assertCount(2, $loaded_models);
        PHPUnit::assertEquals($created_model->toArray(), normalize_updated_date($loaded_models[0]->toArray(), $created_model->toArray()));
        PHPUnit::assertEquals($created_model_2->toArray(), normalize_updated_date($loaded_models[1]->toArray(), $created_model_2->toArray()));
    }


    public function newModel() {
        return call_user_func($this->create_model_fn);
    }

}
