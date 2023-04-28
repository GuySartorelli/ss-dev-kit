FROM silverstripe/ss-dev-kit
# @TODO consider tags (e.g. :cms4 - so that when we remove PHP 7.4 and 8.0 people can keep using this tool)
# Though probably just give instructions for running docker build -t silverstripe/ss-dev-kit so they have a local copy
# of the old image in that case.

# @TODO: Take the timezone from command options
ENV TZ=Pacific/Auckland
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Put localhost in place
COPY docker_apache_default /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite

EXPOSE 80
EXPOSE 443

# Default www-data user/group id is 33, change it to 1000 to match the default user on host
# https://jtreminio.com/blog/running-docker-containers-as-current-host-user/#ok-so-what-actually-works
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN userdel -f www-data &&\
    if getent group www-data ; then groupdel www-data; fi &&\
    groupadd -g ${GROUP_ID} www-data &&\
    useradd -l -u ${USER_ID} -g www-data www-data &&\
    install -d -m 0755 -o www-data -g www-data /home/www-data &&\
    chown --changes --silent --no-dereference --recursive \
          --from=33:33 ${USER_ID}:${GROUP_ID} \
        /home/www-data

COPY entrypoint /usr/local/bin/start_container
RUN chmod +x /usr/local/bin/start_container
CMD ["/usr/local/bin/start_container"]
