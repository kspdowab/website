-- Update State President designation from 'ಅಧ್ಯಕ್ಷರ' to 'ರಾಜ್ಯಾಧ್ಯಕ್ಷರು'
UPDATE `office_bearers` 
SET `association_designation` = 'ರಾಜ್ಯಾಧ್ಯಕ್ಷರು' 
WHERE `association_designation` = 'ಅಧ್ಯಕ್ಷರ' AND `district_id` IS NULL AND `taluk_id` IS NULL;
