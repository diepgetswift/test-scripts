update dbp_config set value = 'live' where name = 'tenant_env';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_live';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_staging';
update dbp_config set value = '019f195e-e22b-71df-9335-49a781855aa6' where name = 'tenant_uuid';
