import unittest
import numpy as np
from lpr_engine.quad_ensemble import QuadReaderEnsemble,extract_best_template_match
class DisabledGPT:
 def is_configured(self):return False
class RecognitionSafetyTests(unittest.TestCase):
 def setUp(self):self.crop=np.random.default_rng(7).integers(0,255,(80,280,3),dtype=np.uint8)
 def ensemble(self,easy,paddle=None):
  e=QuadReaderEnsemble(easyocr_reader=object(),paddleocr_reader=object() if paddle else None,gpt_vision_reader=DisabledGPT(),min_accept_confidence=.65)
  e.read_with_easyocr=lambda c:easy
  e.read_with_paddleocr=lambda c:paddle
  return e
 def test_reloaded_ensemble_can_share_reader_concurrency_guard(self):
  e=self.ensemble(('D566CP',.9))
  other=QuadReaderEnsemble(easyocr_reader=e.easyocr,gpt_vision_reader=DisabledGPT(),reader_pool=e.reader_pool)
  self.assertIs(e.reader_pool,other.reader_pool)
 def test_confusable_readers_choose_stronger_independent_reading(self):
  e=self.ensemble(('O566CP',.95),('D566CP',.999));r=e.recognize_ensemble(self.crop,{})
  self.assertEqual((r.decision,r.final_plate),('ACCEPT','D566CP'))
 def test_recognition_only_paddle_result_is_parsed(self):
  class Paddle:
   plate_recognition_only=True
   def predict(self,c):return [{'rec_text':'D566CP','rec_score':.999}]
  e=QuadReaderEnsemble(paddleocr_reader=Paddle(),gpt_vision_reader=DisabledGPT())
  self.assertEqual(e.read_with_paddleocr(self.crop),('D566CP',.999))
 def test_very_strong_paddle_reading_can_reach_temporal_confirmation(self):
  e=self.ensemble(('WCUOICI',.3),('D566CP',.999));r=e.recognize_ensemble(self.crop,{})
  self.assertEqual((r.decision,r.final_plate),('ACCEPT','D566CP'))
 def test_eighty_percent_trained_paddle_reading_is_eligible(self):
  e=self.ensemble(('WCUOICI',.3),('D566CP',.80));r=e.recognize_ensemble(self.crop,{})
  self.assertEqual((r.decision,r.final_plate),('ACCEPT','D566CP'))
 def test_weak_single_reader_is_not_accepted(self):
  e=self.ensemble(('R181RT',.25));self.assertEqual(e.recognize_ensemble(self.crop,{}).decision,'NEEDS_REVIEW')
 def test_disagreement_is_not_turned_into_a_confirmed_composite(self):
  e=self.ensemble(('D566CP',.9),('R181RT',.9));self.assertEqual(e.recognize_ensemble(self.crop,{}).decision,'NEEDS_REVIEW')
 def test_registered_weak_candidate_does_not_override_disagreement(self):
  e=self.ensemble(('D566CP',.9),('R181RT',.2));self.assertEqual(e.recognize_ensemble(self.crop,{'R181RT':{}}).decision,'NEEDS_REVIEW')
 def test_reliable_agreement_accepts_unregistered_plate(self):
  e=self.ensemble(('D566CP',.92),('D566CP',.91));r=e.recognize_ensemble(self.crop,{})
  self.assertEqual((r.decision,r.final_plate),('ACCEPT','D566CP'))
 def test_registration_does_not_change_text_extraction(self):
  self.assertEqual(extract_best_template_match('D566CP R181RT',{})[0],extract_best_template_match('D566CP R181RT',{'R181RT':{}})[0])
 def test_five_digits_and_suffix_preserve_leading_zero(self):
  for raw in ('09420T', '09420t', '09420 T', '00001A'):
   with self.subTest(raw=raw):
    plate,display,_,_,changes,_=extract_best_template_match(raw,{})
    self.assertEqual(plate,raw.replace(' ','').upper())
    self.assertEqual(display,plate)
    self.assertEqual(changes,0)
 def test_five_digit_plate_agreement_is_not_rewritten_as_conduction(self):
  e=self.ensemble(('09420T',.92),('09420T',.91));r=e.recognize_ensemble(self.crop,{})
  self.assertEqual((r.decision,r.final_plate),('ACCEPT','09420T'))
 def test_existing_conduction_plate_is_not_rewritten_as_digits(self):
  self.assertEqual(extract_best_template_match('D942DT',{})[0],'D942DT')
 def test_validator_accepts_five_digits_and_suffix(self):
  from lpr_engine.ph_plate_validator import PhilippinePlateValidator
  validator=PhilippinePlateValidator()
  self.assertIsNotNone(validator.match_format('09420T'))
  self.assertEqual(validator.format_for_display('09420T'),'09420T')
 def test_server_fallback_preserves_five_digits_and_suffix(self):
  import ast,re
  from pathlib import Path
  tree=ast.parse(Path('detect_plate_server.py').read_text(encoding='utf-8'))
  assignments={'DIGIT_TO_LETTER','LETTER_TO_DIGIT','PATTERNS','NON_PLATE_WORDS'}
  functions={'score_and_fix','extract_exact_ph_plate_details'}
  nodes=[n for n in tree.body if
         (isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id in assignments for t in n.targets))
         or (isinstance(n,ast.FunctionDef) and n.name in functions)]
  ns={'re':re}
  exec(compile(ast.Module(body=nodes,type_ignores=[]),'<fallback>','exec'),ns)
  for registered in ({},{'D942DT':{}}):
   with self.subTest(registered=registered):
    result=ns['extract_exact_ph_plate_details']('09420T',registered)
    self.assertEqual((result[0],result[1],result[4]),('09420T','09420T',0))
 def test_untrained_lprnet_is_not_a_reader_vote(self):
  calls=[]
  class Untrained:
   meta={'status':'uninitialized'}
   def recognize(self,c):calls.append('untrained');return None
  e=self.ensemble(('D566CP',.9));e.lprnet=Untrained()
  self.assertEqual(e.read_with_lprnet(self.crop),('',0.0))
  self.assertEqual(calls,[])
 def test_paddle_v3_text_arrays_are_parsed(self):
  class Paddle:
   def ocr(self,c):return [{'rec_texts':['D566CP'],'rec_scores':[.94]}]
  e=QuadReaderEnsemble(paddleocr_reader=Paddle(),gpt_vision_reader=DisabledGPT())
  self.assertEqual(e.read_with_paddleocr(self.crop),('D566CP',.94))
if __name__=='__main__':unittest.main()
