<?php 

namespace Framework\Queue;

interface TaskInterface {
    public function handle();
}