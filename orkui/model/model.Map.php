<?php

/**
 * Thin pass-through to the Map (Atlas) domain class. No SQL, no business logic.
 * Unlisted methods (e.g. GetParkLocations) forward through Model::__call.
 */
class Model_Map extends Model
{
    /**
     * @return array<int, array{p: int, r: int}>
     */
    public function heatmap_weights(): array
    {
        return $this->_map()->GetAtlasHeatmapWeights();
    }

    private function _map(): Map
    {
        return new Map();
    }
}
