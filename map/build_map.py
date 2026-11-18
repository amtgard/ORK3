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
import re
import base64
import os
import warnings
warnings.filterwarnings('ignore')

import pandas as pd
import numpy as np
from scipy.spatial import Voronoi
from shapely.geometry import Point, Polygon, MultiPolygon
from shapely.ops import unary_union
import geopandas as gpd
import geodatasets
import pooch
import folium

MILES_25_IN_METERS = 40_233.6  # 25 miles in metres

HERALDRY_DIR = os.path.join(os.path.dirname(__file__), '..', 'assets', 'heraldry', 'kingdom')
HERALDRY_SIZE = 40  # px — diameter of the heraldry badge on the map


def _heraldry_data_uri(kingdom_id: int):
    """Return a base64 data URI for a kingdom's heraldry image, or None."""
    path = os.path.join(HERALDRY_DIR, f'{kingdom_id:04d}.jpg')
    if not os.path.exists(path):
        return None
    with open(path, 'rb') as f:
        data = base64.b64encode(f.read()).decode()
    return f'data:image/jpeg;base64,{data}'


def _largest_polygon_centroid(shape):
    """Return the (lat, lng) centroid of the largest polygon in a shape.

    For non-contiguous kingdoms (MultiPolygon), uses the biggest piece
    so the badge lands in the most prominent territory chunk.
    """
    if isinstance(shape, MultiPolygon):
        shape = max(shape.geoms, key=lambda p: p.area)
    c = shape.centroid
    return c.y, c.x  # lat, lng

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

_RE_LAT = re.compile(rb'\\"lat\\" : ([+-]?[\d.]+)')
_RE_LNG = re.compile(rb'\\"lng\\" : ([+-]?[\d.]+)')

def _lat_lng_from_geocode(geocode_bytes: bytes):
    """Extract (lat, lng) from the stored Google geocode blob, or None.

    The DB stores the raw Google API response with literal \\n and \\"
    escape sequences, so we use regex on the raw bytes rather than
    trying to parse it as JSON.
    """
    try:
        m_lat = _RE_LAT.search(geocode_bytes)
        m_lng = _RE_LNG.search(geocode_bytes)
        if m_lat and m_lng:
            lat = float(m_lat.group(1))
            lng = float(m_lng.group(1))
            if lat != 0 and lng != 0:
                return lat, lng
    except Exception:
        pass
    return None


def extract_parks() -> pd.DataFrame:
    """Pull active non-freehold parks from the ORK DB.

    Parks with lat=0 fall back to coordinates parsed from the stored
    google_geocode blob (same data the app geocoded at registration time).
    """
    sql = (
        "SELECT p.park_id, p.name, k.kingdom_id, k.name, "
        "       p.latitude, p.longitude, p.google_geocode "
        "FROM ork_park p "
        "JOIN ork_kingdom k ON p.kingdom_id = k.kingdom_id "
        "WHERE p.active = 'Active' AND k.kingdom_id != 8 "
        "ORDER BY k.kingdom_id, p.park_id"
    )
    result = subprocess.run(
        [
            '/usr/local/bin/docker', 'exec', 'ork3-php8-db',
            'mariadb', '-u', 'root', '-proot', 'ork',
            '--batch', '--raw', '--skip-column-names', '-e', sql,
        ],
        capture_output=True,  # bytes, not text — geocode field has escape chars
    )
    if result.returncode != 0:
        print("DB error:", result.stderr.decode(), file=sys.stderr)
        sys.exit(1)

    rows = []
    skipped = 0
    for line in result.stdout.split(b'\n'):
        parts = line.split(b'\t')
        if len(parts) != 7:
            continue
        try:
            lat = float(parts[4])
            lng = float(parts[5])
            if lat == 0 or lng == 0:
                parsed = _lat_lng_from_geocode(parts[6])
                if parsed:
                    lat, lng = parsed
                else:
                    skipped += 1
                    continue
            rows.append({
                'park_id':      int(parts[0]),
                'park_name':    parts[1].decode(),
                'kingdom_id':   int(parts[2]),
                'kingdom_name': parts[3].decode(),
                'latitude':     lat,
                'longitude':    lng,
            })
        except ValueError:
            continue

    df = pd.DataFrame(rows)
    print(f"  {len(df)} parks across {df['kingdom_id'].nunique()} kingdoms "
          f"({skipped} skipped — no coordinates or geocode data)")
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

    # US + Canada boundary for clipping (Natural Earth 110m countries)
    _countries_zip = pooch.retrieve(
        'https://naciscdn.org/naturalearth/110m/cultural/ne_110m_admin_0_countries.zip',
        known_hash=None, progressbar=False,
    )
    world = gpd.read_file(_countries_zip)
    land_union = (
        world[world['NAME'].isin(['United States of America', 'Canada'])]
        .to_crs('EPSG:3857')
        .geometry
        .unary_union
    )

    # Merge uncapped Voronoi cells per kingdom, clip to land
    kingdom_shapes = {}
    kingdom_names = {}
    for kid, group in gdf_m.groupby('kingdom_id'):
        cells = [p for p in group['voronoi'] if p is not None]
        if not cells:
            continue
        merged = unary_union(cells).intersection(land_union)
        if merged.is_empty:
            continue
        tmp = gpd.GeoDataFrame({'id': [1]}, geometry=[merged], crs='EPSG:3857')
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

    # Kingdom heraldry badges at centroid of largest polygon
    for kid, shape in kingdom_shapes.items():
        uri = _heraldry_data_uri(kid)
        if not uri:
            continue
        lat, lng = _largest_polygon_centroid(shape)
        icon = folium.DivIcon(
            html=(
                f'<img src="{uri}" '
                f'style="width:{HERALDRY_SIZE}px;height:{HERALDRY_SIZE}px;'
                f'border-radius:50%;border:1.5px solid #444;'
                f'box-shadow:0 1px 3px rgba(0,0,0,.5);'
                f'object-fit:cover;pointer-events:none;">'
            ),
            icon_size=(HERALDRY_SIZE, HERALDRY_SIZE),
            icon_anchor=(HERALDRY_SIZE // 2, HERALDRY_SIZE // 2),
        )
        folium.Marker(location=[lat, lng], icon=icon,
                      tooltip=kingdom_names[kid]).add_to(m)

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
