import unittest
from shapely.geometry import box, LineString, MultiPolygon, mapping
from shapely.ops import transform
from core import normalize, relation_and_clip, geodesic, nearest_point, process, country_polygon, PROJECT, road_class


class NetworkTests(unittest.TestCase):
    def test_inside(self):
        rel, clip = relation_and_clip(LineString([(1,1),(2,1)]), box(0,0,3,3))
        self.assertEqual(rel, 'inside'); self.assertEqual(clip.length, 1)

    def test_crossing_clipped(self):
        rel, clip = relation_and_clip(LineString([(-1,1),(4,1)]), box(0,0,3,3))
        self.assertEqual(rel, 'crosses'); self.assertEqual(clip.length, 3)

    def test_multipolygon(self):
        _, clip = relation_and_clip(LineString([(-1,1),(6,1)]), MultiPolygon([box(0,0,2,2),box(3,0,5,2)]))
        self.assertEqual(clip.length, 4)

    def test_boundary(self):
        rel, clip = relation_and_clip(LineString([(0,0),(3,0)]),box(0,0,3,3))
        self.assertEqual(rel,'touches_boundary'); self.assertEqual(clip.length,3)

    def test_water_polygon(self):
        _, clip = relation_and_clip(box(-1,-1,2,2), box(0,0,3,3))
        self.assertEqual(clip.area,4)

    def test_projected_metres(self):
        g = transform(PROJECT, LineString([(18,45),(18.01,45)]))
        self.assertTrue(780 < g.length < 800)

    def test_geodesic(self):
        self.assertAlmostEqual(geodesic({'lat':0,'lng':0},{'lat':0,'lng':1}),111319.4908,places=3)

    def test_zero_and_nearest(self):
        p={'lat':45,'lng':18,'id':2}
        self.assertEqual(nearest_point(p,[dict(p,lat=46),p])[0],0)

    def test_missing(self):
        self.assertIsNone(nearest_point({'lat':0,'lng':0},[]))
        self.assertIsNone(nearest_point({'lat':None,'lng':0},[{'id':1,'lat':0,'lng':0}]))

    def test_tie(self):
        a={'lat':0,'lng':0,'id':1};b=dict(a,id=2)
        self.assertEqual(nearest_point(a,[b,a])[1]['id'],1)

    def test_duplicate(self):
        e={'type':'way','id':1,'version':1,'tags':{'highway':'primary'},'geometry':[{'lon':18,'lat':45},{'lon':18.01,'lat':45}]}
        objects,_=normalize([e,e]);self.assertEqual(len(objects),1)
        with self.assertRaises(ValueError): normalize([e,dict(e,tags={'highway':'track'})])

    def test_classification(self):
        self.assertEqual(road_class({'highway':'primary_link'}),'primary')
        self.assertEqual(road_class({'highway':'unknown'}),'other')

    def test_hydro_types_are_not_all_rivers(self):
        for kind in ['river','stream','canal','drain','ditch']:
            e={'type':'way','id':1,'tags':{'waterway':kind},'geometry':[{'lon':18,'lat':45},{'lon':18.01,'lat':45}]}
            objects,_=normalize([e]);self.assertEqual(objects[0]['feature_type'],kind)

    def test_water_relation_and_member_are_not_double_counted(self):
        points=[{'lon':0,'lat':0},{'lon':1,'lat':0},{'lon':1,'lat':1},{'lon':0,'lat':1},{'lon':0,'lat':0}]
        way={'type':'way','id':1,'tags':{'natural':'water'},'geometry':points}
        relation={'type':'relation','id':2,'tags':{'natural':'water','water':'lake'},'members':[{'type':'way','ref':1,'role':'outer','geometry':points}]}
        objects,_=normalize([way,relation]);self.assertEqual(len(objects),1)
        self.assertEqual(objects[0]['osm_type'],'relation')

    def test_latest_osm_version_wins_deterministically(self):
        old={'type':'way','id':1,'version':1,'tags':{'highway':'primary'},'geometry':[{'lon':18,'lat':45},{'lon':18.01,'lat':45}]}
        new=dict(old,version=2,tags={'highway':'secondary'})
        self.assertEqual(normalize([new,old])[0],normalize([old,new])[0])
        self.assertEqual(normalize([old,new])[0][0]['feature_type'],'secondary')

    def test_invalid_coordinates_are_not_targets(self):
        self.assertIsNone(nearest_point({'lat':45,'lng':18},[{'id':1,'lat':100,'lng':18}]))

    def test_zero_geometry_distance_is_valid(self):
        j={'settlement_id':1,'settlement_slug':'test','settlement_name':'Test','municipality_slug':'test','boundary_id':None,'origin_type':'verified_label_point','lat':45,'lng':18,'geometry':None}
        o={'osm_type':'way','osm_id':1,'kind':'road','feature_type':'primary','group':'major','name':None,'source_properties':{},'geometry':mapping(LineString([(18,45),(18.01,45)]))}
        rows,_=process({'jobs':[j],'pois':[]},[o]);self.assertEqual(rows[0]['proximity'][-2]['distance_straight_m'],0)

    def test_origins_water_and_no_boundary(self):
        for origin in ['verified_label_point','geographic_centroid']:
            j={'settlement_id':1,'settlement_slug':'test','settlement_name':'Test','municipality_slug':'test','boundary_id':1,'origin_type':origin,'lat':45,'lng':18,'geometry':mapping(box(17.99,44.99,18.01,45.01)), 'area_km2':3}
            o={'osm_type':'way','osm_id':1,'kind':'hydro','feature_type':'stream','name':None,'source_properties':{},'geometry':mapping(LineString([(17.98,45),(18.02,45)]))}
            rows,links=process({'jobs':[j],'pois':[]},[o])
            self.assertEqual(rows[0]['origin_type'],origin)
            self.assertGreater(rows[0]['hydrography']['waterway_length_km'],1)
            self.assertEqual(links[0]['relation'],'crosses')
            self.assertEqual(rows[0]['proximity'][-3]['distance_straight_m'],0)
            rows,_=process({'jobs':[dict(j,geometry=None,boundary_id=None)],'pois':[]},[])
            self.assertIsNone(rows[0]['transport'])

    def test_cross_border_target_is_flagged_but_distance_kept(self):
        j={'settlement_id':1,'settlement_slug':'test','settlement_name':'Test','municipality_slug':'test','boundary_id':None,'origin_type':'geographic_centroid','lat':45,'lng':18,'geometry':None}
        pois=[{'id':7,'code':'pharmacy','name':'Abroad','lat':45,'lng':18.2,'source_id':3}]
        road={'osm_type':'way','osm_id':1,'kind':'road','feature_type':'primary','group':'major','name':None,'source_properties':{},'geometry':mapping(LineString([(18,45),(18.01,45)]))}
        country=transform(PROJECT, box(17.9,44.9,18.1,45.1))
        flagged={p['metric_code']:p for p in process({'jobs':[j],'pois':pois},[road],country)[0][0]['proximity']}
        plain={p['metric_code']:p for p in process({'jobs':[j],'pois':pois},[road])[0][0]['proximity']}
        self.assertIs(flagged['nearest_pharmacy']['target_in_country'],False)
        self.assertEqual(flagged['nearest_pharmacy']['distance_straight_m'],plain['nearest_pharmacy']['distance_straight_m'])
        self.assertIs(flagged['nearest_major_road']['target_in_country'],True)
        self.assertIsNone(flagged['nearest_hospital']['target_in_country'])
        self.assertIsNone(plain['nearest_pharmacy']['target_in_country'])

    def test_country_polygon_from_relation_members(self):
        west=[{'lon':0,'lat':0},{'lon':0,'lat':1},{'lon':1,'lat':1}]
        east=[{'lon':1,'lat':1},{'lon':1,'lat':0},{'lon':0,'lat':0}]
        rel={'type':'relation','id':9,'tags':{'admin_level':'2'},'members':[{'type':'way','ref':1,'role':'outer','geometry':west},{'type':'way','ref':2,'role':'outer','geometry':east}]}
        self.assertTrue(country_polygon([rel]).covers(transform(PROJECT, box(0.4,0.4,0.6,0.6))))
        with self.assertRaises(ValueError): country_polygon([])


if __name__=='__main__': unittest.main()
