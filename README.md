Disclaimer: Please note that we no longer support older versions of SDKs and Modules. We recommend that the latest versions are used.

# README

# Contents

- Introduction
- Prerequisites
- Installing the payment module
- License

# Introduction

This Shopware module provides an easy method to integrate with the payment gateway.
 - The httpdocs directory contains the files that need to be uploaded to the root of your Shopware installation
 - Supports Shopware versions: **6.0**

# Prerequisites

- The module requires the following prerequisites to be met in order to function correctly:
    - The 'bcmath' php extension module: https://www.php.net/manual/en/book.bc.php

> Please note that we can only offer support for the module itself. While every effort has been made to ensure the payment module is complete and bug free, we cannot guarantee normal functionality if unsupported changes are made.

# Installing and configuring the module

1. Copy the contents of httpdocs to the root folder of your Shopware install
2. Log in to your store backend with your username and password
3. Find "Payment Network" in the list on Settings > System > Plugins
4. Click the big blue 'Install' button and then click the large 'Activate' button
5. Enter your merchantID and Signature key in the boxes at the bottom of the panel and click 'Save'
6. Navigate to Configuration->Shipping Costs on the top menu and click the pencil next to the first shipping method you would like Payments to be available for
7. Click the 'Payments methods' tab, then the 'Pay with Handpoint' and click the small arrow in the middle pointing to the right

License
----
MIT
