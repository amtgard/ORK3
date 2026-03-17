#!/usr/bin/env python3
"""
Amtgard Kingdom Territory Map
==============================
Generates a Voronoi-based kingdom boundary map using park locations.
Each park is a Voronoi node; cells are clipped to a 25-mile radius.
Kingdom territory = union of its parks' clipped cells.
Freeholds (kingdom_id=8) are excluded.

Output: map/amtgard_kingdoms.html
"""

import subprocess
import sys
import warnings
warnings.filterwarnings('ignore')

import pandas as pd
import numpy as np
from scipy.spatial import Voronoi
from shapely.geometry import Point, Polygon
from shapely.ops import unary_union
import geopandas as gpd
import folium

MILES_25_IN_METERS = 40_233.6  # 25 miles in metres

# Visually distinct fill colors — one per kingdom (cycles if needed)
PALETTE = [
    '#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4',
    '#42d4f4', '#f032e6', '#bfef45', '#469990', '#9A6324',
    '#800000', '#aaffc3', '#808000', '#000075', '#a9a9a9',
    '#4169e1', '#ff7f50', '#00ced1', '#ff1493', '#daa520',
    '#8b008b', '#20b2aa', '#556b2f', '#b8860b', '#483d8b',
]


# ---------------------------------------------------------------------------
# Data extraction
# ---------------------------------------------------------------------------

def extract_parks() -> pd.DataFrame:
    """Pull active non-freehold parks with coordinates from the ORK DB."""
    sql = (
        "SELECT p.park_id, p.name, k.kingdom_id, k.name, p.latitude, p.longitude "
        "FROM ork_park p "
        "JOIN ork_kingdom k ON p.kingdom_id = k.kingdom_id "
        "WHERE p.active = 'Active' "
        "  AND k.kingdom_id != 8 "
        "  AND p.latitude  IS NOT NULL AND p.latitude  != 0 "
        "  AND p.longitude IS NOT NULL AND p.longitude != 0 "
        "ORDER BY k.kingdom_id, p.park_id"
    )
    result = subprocess.run(
        [
            '/usr/local/bin/docker', 'exec', 'ork3-php8-db',
            'mariadb', '-u', 'root', '-proot', 'ork',
            '--batch', '--skip-column-names', '-e', sql,
        ],
        capture_output=True, text=True,
    )
    if result.returncode != 0:
        print("DB error:", result.stderr, file=sys.stderr)
        sys.exit(1)

    rows = []
    for line in result.stdout.strip().split('\n'):
        parts = line.split('\t')
        if len(parts) != 6:
            continue
        try:
            rows.append({
                'park_id':      int(parts[0]),
                'park_name':    parts[1],
                'kingdom_id':   int(parts[2]),
                'kingdom_name': parts[3],
                'latitude':     float(parts[4]),
                'longitude':    float(parts[5]),
            })
        except ValueError:
            continue

    df = pd.DataFrame(rows)
    print(f"  {len(df)} parks across {df['kingdom_id'].nunique()} kingdoms")
    return df


# ---------------------------------------------------------------------------
# Bounded Voronoi helper
# ---------------------------------------------------------------------------

def finite_voronoi_polygons(vor: Voronoi, far: float = 5_000_000):
    """
    Return one finite Shapely polygon per input point.
    Infinite regions are extended `far` metres outward then closed.
    """
    center = vor.points.mean(axis=0)

    # Map each point to the ridges touching it
    point_ridges: dict[int, list] = {}
    for (p1, p2), (v1, v2) in zip(vor.ridge_points, vor.ridge_vertices):
        point_ridges.setdefault(p1, []).append((p2, v1, v2))
        point_ridges.setdefault(p2, []).append((p1, v1, v2))

    new_vertices = vor.vertices.tolist()
    new_regions = []

    for p_idx, region_idx in enumerate(vor.point_region):
        region = vor.regions[region_idx]

        if all(v >= 0 for v in region):
            # All vertices are finite — use as-is
            verts = [new_vertices[v] for v in region]
        else:
            # Reconstruct the finite boundary for this infinite region
            finite_verts = [new_vertices[v] for v in region if v >= 0]
            for p2, v1, v2 in point_ridges.get(p_idx, []):
                if v2 < 0:
                    v1, v2 = v2, v1
                if v1 >= 0:
                    continue  # already finite
                # Direction: perpendicular to the line between the two points,
                # pointing away from the centroid
                tangent = vor.points[p2] - vor.points[p_idx]
                tangent /= np.linalg.norm(tangent)
                normal = np.array([-tangent[1], tangent[0]])
                mid = vor.points[[p_idx, p2]].mean(axis=0)
                direction = np.sign(np.dot(mid - center, normal)) * normal
                far_point = (vor.vertices[v2] + direction * far).tolist()
                finite_verts.append(far_point)

            # Sort counter-clockwise around centroid
            arr = np.array(finite_verts)
            c = arr.mean(axis=0)
            angles = np.arctan2(arr[:, 1] - c[1], arr[:, 0] - c[0])
            verts = arr[np.argsort(angles)].tolist()

        if len(verts) >= 3:
            new_regions.append(Polygon(verts))
        else:
            new_regions.append(None)

    return new_regions


# ---------------------------------------------------------------------------
# Territory construction
# ---------------------------------------------------------------------------

def build_kingdom_shapes(df: pd.DataFrame):
    """
    Build kingdom territories using a true connected Voronoi approach:

    1. Compute uncapped Voronoi cells for every park.
    2. Union each kingdom's cells into one raw territory (fills gaps between
       same-kingdom parks automatically — no intra-kingdom clipping).
    3. Build the global "claimed zone" = union of every park's 25-mile buffer.
       This defines the outer frontier: territory more than 25 miles from the
       nearest park of any kingdom is unclaimed and gets trimmed away.
    4. Intersect each kingdom's raw territory with the claimed zone.

    Result: same-kingdom parks whose 25-mile radii overlap are seamlessly
    connected (the Voronoi boundary between them is interior and invisible).
    The outer edge of each kingdom is defined by the 25-mile buffer frontier.
    Cross-kingdom boundaries fall exactly on the Voronoi line within the
    overlapping buffer zone.

    Returns (kingdom_shapes dict, kingdom_names dict, parks GeoDataFrame WGS84).
    """
    gdf = gpd.GeoDataFrame(
        df,
        geometry=gpd.points_from_xy(df.longitude, df.latitude),
        crs='EPSG:4326',
    )
    # Project to Web Mercator for metric ops
    gdf_m = gdf.to_crs('EPSG:3857').copy()
    coords = np.array([(g.x, g.y) for g in gdf_m.geometry])

    vor = Voronoi(coords)
    polygons = finite_voronoi_polygons(vor)

    gdf_m = gdf_m.copy()
    gdf_m['voronoi'] = polygons

    # Global claimed zone: union of all parks' 25-mile buffers
    all_buffers = unary_union([
        pt.buffer(MILES_25_IN_METERS) for pt in gdf_m.geometry
    ])

    # Merge uncapped Voronoi cells per kingdom, then clip to claimed zone
    kingdom_shapes = {}
    kingdom_names = {}
    for kid, group in gdf_m.groupby('kingdom_id'):
        cells = [p for p in group['voronoi'] if p is not None]
        if not cells:
            continue
        raw = unary_union(cells)
        trimmed = raw.intersection(all_buffers)
        if trimmed.is_empty:
            continue
        tmp = gpd.GeoDataFrame({'id': [1]}, geometry=[trimmed], crs='EPSG:3857')
        kingdom_shapes[kid] = tmp.to_crs('EPSG:4326').geometry.iloc[0]
        kingdom_names[kid] = group['kingdom_name'].iloc[0]

    return kingdom_shapes, kingdom_names, gdf  # gdf stays in WGS84


# ---------------------------------------------------------------------------
# Map rendering
# ---------------------------------------------------------------------------

def build_map(kingdom_shapes, kingdom_names, parks_gdf):
    m = folium.Map(location=[42, -98], zoom_start=4, tiles='OpenStreetMap')

    kingdoms_sorted = sorted(kingdom_shapes.keys())
    color_map = {
        kid: PALETTE[i % len(PALETTE)]
        for i, kid in enumerate(kingdoms_sorted)
    }

    # Kingdom territory layer
    for kid, shape in kingdom_shapes.items():
        color = color_map[kid]
        name = kingdom_names[kid]
        folium.GeoJson(
            shape.__geo_interface__,
            name=name,
            style_function=lambda x, c=color: {
                'fillColor': c,
                'color': '#444',
                'weight': 1.5,
                'fillOpacity': 0.35,
            },
            tooltip=name,
        ).add_to(m)

    # Park markers
    for _, row in parks_gdf.iterrows():
        color = color_map.get(row.kingdom_id, '#888')
        folium.CircleMarker(
            location=[row.latitude, row.longitude],
            radius=4,
            color='#222',
            weight=1,
            fill=True,
            fill_color=color,
            fill_opacity=0.95,
            tooltip=f"<b>{row.park_name}</b><br>{row.kingdom_name}",
        ).add_to(m)

    folium.LayerControl().add_to(m)
    return m


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main():
    print("Extracting park data from ORK database...")
    df = extract_parks()

    print("Computing Voronoi territories...")
    kingdom_shapes, kingdom_names, parks_gdf = build_kingdom_shapes(df)
    print(f"  Built territories for {len(kingdom_shapes)} kingdoms")

    print("Rendering map...")
    m = build_map(kingdom_shapes, kingdom_names, parks_gdf)

    import os
    os.makedirs('map', exist_ok=True)
    output = 'map/amtgard_kingdoms.html'
    m.save(output)
    print(f"Saved → {output}")
    print("Open that file in a browser to view the map.")


if __name__ == '__main__':
    main()
