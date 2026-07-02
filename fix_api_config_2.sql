update dbp_config set value = 'live' where name = 'tenant_env';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_live';
update dbp_config set value = 'https://auth.staging.deliverybizpro.com/' where name = 'tenant_service_url_staging';
update dbp_config set value = '019f195d-0386-704e-bebd-6f2ffce8ba7a' where name = 'tenant_uuid';
