<?php
namespace Redis_Cache_API;

class Cache_Version {
    private $version_prefix = 'rca_v_';
    private $group_versions = array();

    public function get_version($group) {
        if (!isset($this->group_versions[$group])) {
            $version = wp_cache_get($this->version_prefix . $group, $group);
            $this->group_versions[$group] = $version ? $version : 1;
        }
        return $this->group_versions[$group];
    }

    public function increment_version($group) {
        if (!isset($this->group_versions[$group])) {
            $this->group_versions[$group] = 1;
        } else {
            $this->group_versions[$group]++;
        }
        
        wp_cache_set($this->version_prefix . $group, $this->group_versions[$group], $group);
        return $this->group_versions[$group];
    }

    public function get_versioned_key($key, $group) {
        $version = $this->get_version($group);
        return sprintf('%s:%d', $key, $version);
    }

    public function clear_group($group) {
        $this->increment_version($group);
    }
} 