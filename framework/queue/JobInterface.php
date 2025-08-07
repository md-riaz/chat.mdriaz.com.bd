<?php 

namespace Framework\Queue;

interface JobInterface {
    public function handle();
}
