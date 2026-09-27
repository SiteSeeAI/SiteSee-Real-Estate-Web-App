#!/usr/bin/env python3
"""Install the reviewed SiteSee branded TEST checkout in one WHM session.

The publishable key is entered locally. Existing Stripe secrets, webhooks,
mail/calendar credentials, PHP-FPM and live-payment gates are not modified.
"""
import base64
import datetime
import fcntl
import getpass
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import sys
import tempfile
import zlib

ROOT = Path('/home/sitesee/.sitesee-real-estate')
PUBLIC = Path('/home/sitesee/public_html/re')
PHP = '/opt/cpanel/ea-php82/root/usr/bin/php'
OLD_PAYMENT_SHA = '845ba608002c03e761ed5296b2301f5f32dd4891705a84c718aadc14c1ede120'
PAYLOAD = 'eNq1fetyG0eW5qukZY0LsAEQAK8CCWooCmpxLIlskmq3h+IiCqgCUVYBha4qkEKzGdG/5gG2989G7O+N2CeY//Mo/SR7LplZmXUBKdvjmbaJqryePJfvnDyZdf9s4a5m/jxtuknip8nGKIo+B/ObpnzcGifJs96zf51EUGTijv17+dcsCFe9k3nqx/tJPO4t47DmbMg2sEiyQS+b5/7NMnTjVppOnLqYRPHMTWtOGi/9dLXwnfo+tXfnBzfTtLfVbvNvL0gWobvqJXfu4uHTvKL7s2ixCOZJ1QDk6+aFPwteRaH3tDHstNtir2IcvTiK0vtmc+7ernrftjvwj7/fbN5A471vJ5Nxp70LP4P55963nW6308WXs2Xqw9vtvZ3d3Q78DoO53/vWG/sdfwt+LtyFH0Pl7cnuZA+6+P5+FH1pJsFfYRF6oyj2gIbw5GEUeav7mRvfBPNee3/kjj/fxNFy7vVu3bgmm6nvj6MwiuUjGAbPrNfZXnzZ6LR2BC1J4ygO3LCRuECaxI+DyT5MffQ5ALLilJMZzHGKvbvzFAoGbuJ7MLBppzHtNqabjYUexoN6VrYqxW5yVN5HSjSn/LvT6m4/uPc8/mA+hQrpw2iZptG8EcwXy7SR+KE/TqmnfAHXKtJIljMY4epeTSt1F80p9BJiT03uIo1hXAs3BhaXrdyPl3ECbxZRgFSST3vAAe4o9D31+s4N0v1o4Y6DdNVr7Ww/XE0Dz/Pn1/eKV+bR3P8mmC2iOAUKPvQm0XiZNG+DJIB27qNlShywufgikigMPPHtixe7bSCHfNOMJhNg4t7W4stDK/kcLJBjPt8voiRIAxjQJPjie/tptOg1O+324st+6E/SXhf/+iusued/6W0X+QNZtL6/cD0PV7bTtRrnId5jm/Tm07yV+knaHLnzuR/riU1C/8s+MMTNvBmk/izpjX2S/1+WSRpMVkBZ+AmrIx/fuIveCxiV6nQXZtzZgwfG4EBoJt3xpuTbb7dfbGkVACLg03CKfOOnqFkSXARot9Xe8mcPPGIPxFNy1A7UvAu8dEp/mX2+2CF6S+GKXS9YJr3t9r/INvwvMNsAJ55XTbmO2/ssCE1agW0mnFKnU9+F1u8LC4GqQ8kpzv6hxSVh6bDLmfulyaPudLdwTaWsucs00pTsIvNsImm+dmlw6H5z5Kd3vj+nFeoSn41AHLxHFxrLU7ep/wVo7Y+j2CWeRJaXjYhgdqMbGoXR+LNchc4uTkcuzjYww340+gXEtTkJgGVgfEBz2cYhDHN+b3ABzFeRbNwZj7yuWjyifCcTpc3x1ovtLUUofr3ZJmZfLlAim4spjPWeJkAz7MU4IJPldismmOfDXJuCBm3PXI551B1NxntmH50cWyN3SWYaRaB3ZqgfTHaaAXWexhybijnE1h5NPPUXydOWdluNKpM9OYNdd297b1yUvO62P8sPfFv3apNkXdcvsjojXSGYkz78SrXDBOruZsxGfzPDmLzi7YDe7hbVgEmCdnGd1DhbHjKSUlybO1s7vvUKJmIqHX/Lf+GPVW+yGj/U1cDEoEG6L7XiNudZFeyuTIVvdWe8QNbyV/4oju5YFsgeIjLqAU/78RiMfmG1O6Bn1zEx6maL/x9aBH4UkXYAAe11H4Cnb3yYVxpH9znW6SAbZK+FHqNdrtPOlZt2DG0xDt3ZotYFlmpstrq3d40tYOR6fjZNMBtF5mXDmLV8uOiFLtiE8TQIPWtdaGb1/Uwkd9ttW3xyBgGoAhZeM/dNHHj7+K8m8DQ8SX1cpeVsnvRmwRyarW1ig43W3uYkrgv5rN3otDq78IB1N1pTUzKS1I1TUKISwysgVOB9ngJKVz1vjXPygLYzuvXjSRjd9RjpPLQ8n9AI2Tjo53Ejl1muPVRObS2PTcQcW7lxMYfme8mYQVmCrbHvbWfl3BmMQAJEBPFbO4R6N0UlIC2yRMYRBFfagvjB7kFo64TqQCDLbHSqoHUZVrFgg6ll3dHoxXgr62/sLtD63JfrZCi7PfbQCtEqg7kCExom95rWu5LWgm18rpyYdo2Gux1Cknl6kH63cfqWItALIhDaqRYIO7BhuiqTENuMZ8wJ3lyaKFcCRyjauZeHXnD7A/xPST/hU5jUg5c2PC/zQuxawkvvc0rqyRquvQvTVdpqc7ezOyqxyvnuPLO7rWKBZOaGYTkwAIW4u9fO61RjutQfcNJtMPaTe0NmKmRZ+YuszErLaB+ASJ6z91lnQikP2y0qMUW5ClI8wshNJbayzKA/6U62ckqGnBc1LvC+bT3asYa1DO8tdEcehWQFZDBgyfZ+kQ2NFsLghzAweWoHO0ij1A0zfuzIlvLk4WLImTaueQLQNgAbGjBkd24NWehuChqcGNEHsHkXu4sipWXp1sgN3fnY13RQftv+o/xRgmBZJ8yj1M9LTYksWz0Sb8b+xAcIAqN5tHOr8p7N5+jR5HFXhhp29joVTp/uXiRgrcEUmXxXDqOyCRaa3IMmDYsNfOyH9+X2cb0AGsZTw3JcILDiEi20H+xuctp4s1Qbb1YDFqupTXMpdzK263aJqxV8Muoc6qBOh8rsr1fkbE/AeQPrBK5J/Fk5y79C53a6mc7ddXd2tvOekD3cPKdulnIqjW4Zg0mihXmSD2Iqkm02/gYbbo92vN29Er1kdCOS25t76aBtZ/4H/Y09Sz8ZphCgwJiMtTnZmexZXu1mGSrKtPeOiqaotW3n1TnJJ/elpCMvEQ8t0FcJuhyVBMIHTcKVRCTSM5bRrvAYwSPq7ox1B4IidPeaCioosGeQyZiNUuTuGBfI8l8IW6KYyuCfjN79Nqexwn0q9KukPx892jQknZiHtT0wsZzc1osSZynHxqVBhxz4UmHJKYLyRsv8ldNUXnsLoOQiDkjBuySrijnb4OWutVj5iiIXkaHpWUODGsCs41W5FWGk0zFVPkmFPbvdh5Yfx1HcuIqj0O+7oR+n1wru7/ndF912IXjo+zaiIX1XXJwcqZWc77AdHk/98WfwzpDR02VyX1F6q0I1Gg2wD2LKZHOPahm8sMMBKRhy6N+vdQHbjV1sA9UZDYx0bSmrV/B1iQQwC2wZgkd/F8MgFmzzJttAabkUm52dra2OQdPudmbOpdbuMprMht3CyHnOW8QV3PZ2tfrf255YYtEtsW6hO/JDkI20pnRLPQeuK1Zvj9fabo3UErV2hXtBfVrIUfTlum48lBH+eiNXWW5JlEY7UcQ0YyJYMBCaGYVyx6O90aSEYw1R2S0GzQvgxo49h4unBBo6k1jA/0ixb28b2ndbql8TshlRtGqQ92CNwQY1KD5lLmYB0+yZi07tLPIusK039kplUrUwiQAyPBJZfyp4zULvHMX4NT5AhYLE4FgnP+qio0HNbJsU4pLCvS+PyueLSWORLwyM5cc4V9xunfle4NYytNppb6Maui9ElvSuFMx8/HlF21IUbTBa0XTfU41kMX5zz+whHypbqxq7Oxwd285Hx7ZVdKyzV1Ae9+YyFiIijUJgy/I0u4aRb8YcWd4shocMVt3cq6AExQqBEtbWj9YWuxI/mDsqch02DVi5tV3c1yhg9OIOhe1lPdg7DJo+W3IMxOtqE4Goul301HKaHxXFI+jC2B7ARnfM8L8M4G8aAXzbgr9YF/3Nx5DtCG4eHj+J45SK7FrBwByP5N4WeeFFCcfl+LGCVwlPlvl5lsvYzWvNJ8zGcsF3DfamByVaJhtUR4FcchAwYNGjqAXxCM3G3ELOMYxe89yGqxkYbRuSsyA/P2nGvrcc+x6gLKngmgsdAajfS4dAguJ7cj5ZO2XGU4CCSIQPjig2/6zxWPrLL5j9UqvVRf9Q3H+aC+EsE3KngnHq7OMDBCEp/nsS3Ii+uAvmXnQH6C71L3z/WOJC8be/ifsHozw6xVDai8ZL6ufGTwehj3++Wp14NUcNS0Icp27UJYz8KvrylPpU1qrNkGxd3RwalrXBcovRMllBzYkbgiOvHqri8GK+DEOzq2l0N8ABwKvazE8SkMqMkkIEE1FTk6mLez2xFhqnY9YcUFXW3M/eMyDTI8leUCJDrb4vHrAHJvjGhric+mLkuzHYvzT6DDVhZqsEwJ9I4Q0ggCn0FIxBTDxamY0EugTGaQgAgVzG82J4JkZu3MJGcei85kYM6rvv1PJPgySN4hW8A9Ye+xdASJi5nLbkmNB3cQpz/058PH9XkzUxlI4825pCu0x5wWVbCcxgPD1zY3eWgKIB2vs1h+bjqIJruq/h4jSE4zRkcws3nc7dmS9+sNrnph6yVYz9dBnPL6MLxTg1cw3zwwY5Apej5hiC1FpMFy+Bessw7SdL8OiT5DtNtL4D/cMfked/PD85jmYLsFTztEDcuhzXvqI+SRBQXBb055Smo4mMr1uwaoNbaO0d0MMHBVNzkuVoFqRAAzdZzcfAfvjanI8Q9Aj8X/rva3/iwrhrisDcN8kBiPQ31AtQGWzsn8DX8oJ0VavXJcl0FSk2mHCmnzFtWVUhI2NDf1n68eqCnIoIxsovnXquDjlAUIVfm7JidEivVO5Svu9iTSjhnME83Bh32i4wqOWLM9Yh//z7/3V01aIIWi2n8SojJNMKPahoovjkAjTnwhff9KHDyXJOwQWnDiIWR3ckCqQvag5KbMLDkKqMVeY4WoYeSWUYuV5LnIWoy4HgE2CwKbQTJAKNv0DghKNxb8C0tAwiZmydLFC5whRcdE3FxE/H0wLjAqsYExKgjNJp5PWAXKcXlyhLYJN8SpRL4GEC8tSMABwGc3zngm6Ep2CnUB59p2G2xOAPat2LIxCKRQol3cUiDFiSNn5JgDTioSEw9a9HxHkDJHjtpi4xfz1r7MGcHhL9m5qansSYCWp5UO283E3Oe0QWdpx6K5iPw6XnJ7Vi//WStamk+ciHcflIX3D0l0DECrqDQGmq63Fid7XCPPTr6HNxJNwUx4xoLsg1FrvAyJZz9xawFgqCZpcKxsAeZZsz0Eeij1w6jUB3eE7dZgOeyzIODQ0uq8JDs1FuFh6CSonSCGAYc/80TRdJz8Fx40vshrQxvVQ2tZWQvLTG0axKStR8MZHPEI+RL25xBzrwPXuSlSqbRmEYHf4np8qkZVhDr4UbeDSr4ht/jtgRSZkzKmixZUePtg9oMYhpQQrk+DlaxgIVdxAtE00YacuFrNgSrGHFMbOpz5wAwEBEMELhCmZrk4/WswmtmD8b+aAReepS58lC4zCAhkCpwhS5MK4qEWLtiuYUHqzoglR0fkUVriPN2rc1rbKji+UoDJIpSsGP/io/G8VudQ3mAF/gnsHKkkgD6bHwKu6MfQAYA0kBhXnPQCXU7lmtHhsk6CnbS2a3jEYPZZ22yKOqOd9yp0313CIFLVa5aRIS+ebNHkIOhFJihdwj/Tdpe8IVUD0EYjpGM5W4uTCwVjKOozA8mafRnwL/rnZPEcKeM0comgAKGflTF5g17jkBLKELON+Y+gPYDyCdhMj1vF3N1uy+bNUKsNwULA3MuW3BvY+RZxmvv2RD35LQW/TEOs60THGFbrVXJvMgeKaTYO6GoYUdCr5GFbSpKGEvM4GmrEP+48HAugagX8CaGcjSwvfy2TiJJ3pF0AlyU3S0U4TH7X0T5lNjfYvl9SR1pR/6olOFoSRQBAyQmZoLwxOo3fMWTU/5fEPptjX0wHv5mTRw/D1jLg+/CR9JTCQhkYmIbEDEeMiAQwoC3UsAVMQfDw2c+cN6XFCwUb8CaUg9hNaL7AzqDhStvLEEpQDcXmqwtMAOseLG9+Kd79765D5iiCMYB9r7JmNt4JLvN7JmcDSaMw5Ep1vXQQU/vQxmPgh1DdmqIbrb7baayIPtAhbKdnRZ6OmhTgR41niGaTF+rOMeWpPA0j7rPTt4Cf/9NPf8cQgqq8ZxjyGat6TfwRZi/y9LsKrDCOVjOHx9cj4cipZwdIO00sQoUPrTHJzxc6iCYZ9kDPbWQ5SC5hkBLvwJasud3/gJUU06C+54THl3oJfu/NEUGlaVwBXnpX51evrjyYc/DI/fDo5/PP14OTw6Oxn+aXB+cXL6AZV8t93dabb3mt2dludOw8Dl0SgPRMjRDtX0hywbtToYqzh2V5/mUiKfo9eMwZMgRrRWk1OuW3PWRCQW3s+CHd8EyXAShH6NmtGOorhypPvq9A9JnYGfbljs4Wd/BW8c59poC5pCLpJNIeqoYdMLPwadIB9+J9rt3d06gY42FsESGIZTBQ7FVvvFjmVbMkhyDkQHFhp8QdkEKgHyl/qf0D6TaMn7B1DB9xLSaHOSE638JV8/1xEyJMoQdx48v1ZjJFTHcQ3Bng6lg6Jm0CA5bIi9hvi3i9MPw8u356c/DeGPwfn56Xk9R1paqprsiUkif1yBcYYxO9fi5Ut0eRiGXQ5AX2UzR4ceWoFVDLN6amGoJhrSul0BINnNcOaSatz4H4vPQwxpDq+Omv/uNv/abr64vu90G9ud7sPzjdegK9WEsw7y66yGWH/yqgy+LADPgii5SmJwDMJoWEDDhRWRvCcHAu8e1otEAn/N3NrZ61Px3BuBZNxGgacFAwT7QyQYxiF6gtYTlNixG3tCpZyCDhGkEDyOvYF7lpIClDzijZqH/hd/XHOOzwdHlwNxefTq3UCcvBEfTi/F4M8nF5cXxYEtA1HLKJWZ68vBny/F2fnJ+6Pzn8WPg58byuCKkw+Xgz8MzqnVDx/fvYM3i2AolQrXU6+44ToRjwi08f33gmI9AHkxVgTWGuYaB/Cnt8FI2NM+BwGhJC0JM8pZoOpfR/Rb2VONeFs814FJ+Ru4oiFeMlOJ52i7mEl7gp/p9SEZsZl1nOSZdXj9Q55NZYdXTuBlrGmJQA27RXnCjhEjfTN1k+kQtDxoMnyZNWe3VmhHvw2DWx9dK0PqqAfSjbmyWTkt1xII5UV7rhSErrsMhmb1Bipi6cUMUcfBk8yxu2ZlVDlo5v2h5r+hQTEaFy7WlaPfO9dVLQGecmHRXef6SsGtoVHtV7XJR0XGqxyplolX1IAB+FlZTd7KGlL2aZUarCyNndSgvToPVLpXQ8xFSXKDLVsgCWWN9UEf3Wk4Y+m1wZ8qIKDW58l6UypLJatewN4MyQdJrJLRKs1ZGObjSpRh5tdLcw6FYIeIzKv1RdY2twqtmeZStkA8QCQ1sAiJRP9QdULxKEVu8Ax1HAe9Qk38fTUyduH7ZSpESggX0Yy4X6mgHEBV9OQvy8iYEmsPEM0Nh3CXbNA0ulKNydF8NUsoVpAkcQWRVShVoFimijE0CTPlAaM1QxxEXvrr2rQrHxOJfP0vQYLJUmqBwcf0bjB7Y+5JnIyLBgYVTW0ED0HlkaFXI3vErFBCpzLlDaE4LnMQ9SPaX8p+Bgtg0DH46tTbcz5GHcUqymC95MjpZa5IgZlp4dUIySBJzFWzgs/Fxbr0yTGmiowtVFQgW5jngHwMOaHm4ZF2/6pQDiIc1QTOCNowJouMWyLa5NcovG+AmVeDP5x8ECfv3w9enwCqyQZHLbL7frrIh6xycQAUY2MmYJ3I2Iyj2KvRIpqrx8tW8JqxDZOmJ3Ni7KP4hiJaJm05qG+ElgPcL2U5AFingqmFwCjrea23SXsorY/ONS1BPppuQ7/T9+9PLqHhMvrkgz2lUkch6OvymDVotRmyInUo46kg94N3g+NL8b14c376vhRh/vR2cD7IwGX/pTVzapUnsARNdZWtxbVVDBrqq8IcT7FeR3dzCmlhOQBSbDiXAVhVBq6SoIZB1UPUJYz2AJOfgcFBD1+HxcMVrGSyhG5YPGlxE1Is7mwE/hyGzuUrydFJy17izE7bQyB7gYo5s9IIh9FUKNtM8JCn+d/LAnLXBtTuMg7hgVpSUnx6YasEp8GKLq/B6tfV+yDltGD+lwY2P1+y7xoaS7lB+zOUWnyYY+PfSiVlQJHDUKnVnD8McA+TMPrVNScHoMas5yvqUFoVoFkDN2wqUXGN2nM7OjkKSR4+nQPTuno7i3XPSkQT9iD9LzBxgZO/zcAchkEC8EBlBgTF64uNW0rg07OPZ6/R6dQm5WJwKewV7evBNuTmh6Ek4rCfeYz5fwr6Qxx9eC0K6y2f53slDipvmJuhqCIYmZrjyjzrodK6pHAbGFqNI/BRpQL+9Kxeqq8asHLX+aV7KJU2uZi/Tia0akCVgIa9VhfNUs1GIksRGAyGtgtc8pSAldoMHvmIYvQ2WmHPAndG4jRc2XYtN61vnqAAYXbeynBOSnThV02E7LECg2N3juiUtyvhryngwdxuZ+UEnqsYiPIdy5dHjdse4cv1pkf8IDrgFGAqbqexvqhl9r5eCjUxGyLfdv9lQ+R5B57ZE5FpdVCHistfMgBkPgoWQ4xmgFgWBBgkyG7UlCc5mAZzduNmRk6ZM3bqDR2rPv1wMfhwqcLUDeyn5iRTt7u944AYLuqNKhhhYZcTaOb8Upyei/PB2buj4wGGt07Lw2SZmKsBGoGvuvjT0buPgwtRe9mA/6s7a+Zn6AvV0roYfHH4X2nC9N7KJQoJ+xfl+6L5hupmf+en7969Ojr+0ZIOljtuLhe0lttuV1lhA2RykAkhhgwjGQ6fDh+pTTneI+Otk6v2NZbF0KiZEuSMl0kazfx4yOwdzbGUG965q4QcSPUaHJQAQQ0LF/+6thoqCUVh8Qzn6BjTVSHAdG0XNZrF4wFDOkwEM7haxAG0jG1cX6noEs0LY0pPq7acg33iiBHWtLFQLlb0tBbBxnnLcSp/4RYNDemC03EFRhSW40kQJ6lU3nR6FRUSRhJQ/YNilT2vmcRflng5Wsrz7VgF1YIHtJFBA7lKwGAuhpMlmE1/uMQddaoYTSbK9D/ahFqv669ZMDbUQ4bAFyeXg4vBYHg+OHo3HFxcgpId4rPhx/N31vZVLmWTG7EyNlsidu+gVc7brGWd1+3ePbAhsBiAbGQAKcfTRmE66kAI3U9RsydX8mzIML+Ij1TDVPMhX0VHtejagkfq8PEfsBarkLvCYbtzurnwsao6tXxIJ3moOp7Qgn8eq0qJCnY1vEJQVbuuCAQUULxMRST0jhoL/sT888T3m0ofNSnTHlcuWyxcdH6kVHgB/H8t8C/4xHlPu9y8l8DganzdeAo6LofblTDCfquRBJj4dU6+HY8ssYnXhegINwGUOqZsJo7Cd9bHuV4p2Edb4564mwahygUjOCvPKpTEYr7G2/oNztDv4t3k4dQayGER/roC6ZZ6KU/DEV9JCoaYvx/PVcIuRpPNTrfzKLeVgxodaT4H/6QZoWMtyRTC5JaLFh2VwHRQ5DOZbBEjwphzYFq5GbiZGeHFB49Fmrn5LNRs7XRUB5IrNjD/u8LEXxO5lATjKGOh/np3KqutnjzeQLZHQ7tklYEiY2Ryg+Y3BrCrokVVA8hiSMVwuyK0HOJX7FtVTtfcOPkQCYxuBGDFMVUu8JNGLhehIcAdwTwIykpQbGwmJoTRzQ3GBB7jZyJSTW2IMK43Nkg8zeNsirMXIKNRiig5e4jmtnQ/xN4ISz4Xd+oxr4STSqiVqq1UOb4rXjyGCteNso1sLsn8ioUpVvvUjAGM9z19m03uO1jpKpKwJYlDnGWP/wFEHKQ1mTq/sQG+q5Egv3Hb2VDLpA5wJQhvSqb2EjcPCzAW5oB7mk7dUgvcv8o+qD9l/zB37CDb8JEJj+hSOkesQv/K56/FKz6f5rRwOUGMuKnmn1R6nNNa62CbIzanSwtuGzc5iKtrzIw7ydiy+aO/wn5MVt2vqidTa5uX4NACBxt5m1+ad3d3TYy4NTVtPTOBmlYSUHC0SJm6jWNwQU7PLoc4WObNJxd/czJ49/qigSwxHC2D0BvSKaoaSx8oI+c7p3H29mz4x4+D85+H52+ON1/s7dTzTGZkuzpqrEbHOrUMu79S/b+9vDx7Ozh6PTgHB0xSRw/u+PTDh8Hx5eXJ+wGsVf9wW7/Rj7ptw0FQb9+cvnt3+tO70+OjS1hXlQ+op3x+enl6fPruon+Ij+gnjeNCF7m4eIdccfLm57MBjowy6Ir9ZMXeAhlhMLqBn87BK3zz8cMxDwDtUDAWWiVKKigVNp4u55/rAo/F1r7TdAStBo5XHmIid0I9YIpaVvIH9YRbOuxsgg/U1dasXbIXwXm8fa6xLwvazZiYUAOjvCcVfe7TIlNwiKZV33/OCIBf3IDLOp9EBt+dfHhzCi70xRlG72CRXw80K2VZ7Mw64zBKfNWs4jQSUABVfTOdSfZJpr7bbj9Jx+TPOSEEjGLwCGAA5coHwwd9M/tSk5JkrtHdajw9yRIbewR4fZz7KjNRjlkvnZPDA9QcG/RiTrKMR/wu6ciU7Ij3/2OMXGYlP17JSIouVCjNBL4N/LviYXJZfS2SlexJ+kyRa0irtd3eVFTzv6AZJGAr2y4DtnhpPymlmnOMmfdN1NlxFPaEysBvAGoKbgF2NTBo33Rv/H6bqqp6f26eR6MoTZqX7g1Wo/vl8VT0JApDhGfzCE8iBLd+rtqbGBP/T4kTkp54Pfjws1XiHL0XsHfNM7o1ioYUy2dU8PmcqNsXIzfxd7aG0kZjECWaDUcroFutu1U32vz0TNmkC3Upm2rc41O8zSQe42nMue/si2QMLJk9GvtN7tIRCl78khjoQj/9vlX+vOTIHnSCESXuYzlP3AleSoGRROifr0TAN4kfTuBBMLsxfguUiJ7Ra+lQcPOeewLGnuNt6kYL5TBp3YD1O9VuaVcTWlvs6ClNfR0V0eFMvn5ECDbkTWWKnMg3AEACveA8bDzmhLyf6OfyGvqMNzjqo0XHAjp4qmhjms5CPGDlxoAO+st00tzLsb9ZKZOCeZSA8E+orOFjgAjTdoH2KdhxQPv5XN/WAGZBOxYRN4go8rouD05o9+EmjEZuqLwnYun9Sp0i29d6ZTyNaDixj9cBSRCVdfgDwFYWEgA89AcAVegIfmaWAQ2Cztp9yXlLPc4OK2xRQEUze/WRZqxEV0LqWfAHVSLrPIuudNZHkg0lyiJrH21tnn6PU6mcM/JHl8oYRNOYrLByO3hca0xvNjflAJPFUUFeGK0LGIbiBTIvTG1OYQuqTkLHK8Dq+PCaTo5hovXER6sNlG83HDxygXHoDUDNfADSkfCRXC4MF+kHeLAL+8fy79wv7I/rrii9sC5tHcamYEGHF4MLdFWynGY8eYZp4KLyHer/YN6d+l9KNb8RyzYTTskxsJkFOOn5EFy/wlNOQOW0n7JG+H4NswHjCVfmXUduAF0uitMEeJ9MTfeJIUiaqs7gdW851as4dcZEzrWZRaZEwT4ApNKlsS0kY34W/EYPmXnoeZD8W0JhHVqTc3ACrpzzAfhHF5fD94PLt6ev5ezQVa4bbiT3/d13CLWH8pMdSc0gmWoNHZLh0fHx4OxSE6pRcrsBUs/A5M+9kbHT4I10ctyvzH18PNhLv4ZhdFPTW4SpgapMIN2jzUOu3zwEx+A9n42t2dECJm49Uz5XDt/BA3KislHWoPWSU7TXDQP5aVvhHCwOTQC4rtGDjcWh0xCymQeJQDkZ9P6JY39qQmh2Iwelp9Chap3EjAYb5rPV3iqZz7R7+M5P//n3/52IN4AzBZ3ml3sfMAV4jVP+nYZBR5OQg8epUCvvpuLAFXgFQv/Ts9QPe529drsL/7S3Nz89O4QfAn408efBhnsoyQqTaYCEBWlIutActXgHPYEupYvI8OVPPkwPcPoUeBUvHKDLDWFsInU/80FRcJnwkKi/aOkI51M1BMaUWNDBONMfKPC8WVSjE5S1NU3hWUBMriKVMkymwWR9cVSaqKNNbVOlxQ3LcAe43JeesdKdv1oN4THgzPKhRRryUeMaHf6zzyHhUBoF9c4DfPmSDtw9XRrO6RSwuvrEOBSZu3YGeb1cdp/YguIxU3KVAkcKqHkw+JUzURdxWMfB9eSM+dAWhsojUTsfULlk3+O6fABPqWqR0M7R1ubz67LGn+MzdbOYY+6hfC0vFdKHvoalqvi9ISr4rPRo51rWUDqrkjWEwWAluYoFBlGnv4gEMuY+nPt3Qz43XtwWzsXM9C0LWiHkm6hOqr5ycIvHSPTRo/N4q0sjBYlJi2gMn6tyhTTV3+GYROUesQprlSYpW/yYlTXv1ag6aoGuja5g4wp164aRwyxP99u3whDIK0MO1Vm8D/noNy2iQTe1zWUmdahsOOcAL+4U49BNErCUxh3Pn56B/QjcJt/zgVYUfAUwnf/8P/842MBKh2jlyUS+lhetnCTinFYHpiCNvG7ZvPMfWrmcumC4wVy2EBtgFqzgMBRZTukpA6nJ2APWxC+WENH4DheSFxOq6UWry6m1cG4LQZePf3pG149Dt3QKDeTTT8buwreqtaB8vlXpp+I5Im8Z+kMZLKQNWjP27LsxuMBE9BrGT1+DCOCNDSez2ZIORdecf6XdK5kc/YPY7YrvxeYO+Kz1evNQXvDw12juW/X/HR84RzM/Bqi9cTyFf99E2ZZWtpA0WyC45JvjaQRGWRzJfOKjGCODofiJGE7BL1q6eJlM1XaduHMTgfHYYI48+CHitxOfNsHQA6Y9VaqnD7WpS3bugjAkv3mFYCjNsbqxgrx2Njcj7Rf5xbEzCHEoGOsOCHqAYkef4zpbtrIGJRlc5Ke5h4fXpciRT4ujB5kFOXcneJtynjfUojYP1Vdmf27Omp646QW9RBzhdWqYZngMc4qBuLheogbrOgX6JLJNpIP6sBtHwik/vi5zM/gJRdwSthGcuX43ZZwr+IrDKpLRdgFvEKKERQmy+AFdg07XVsFDll6QZYwywG+0XfALuGGJP/NzrvTrsb9HG2e7ZDRfNCiPtqE1+ppRZimKTxoWGYY1zbHhkE2VkJnuGzo8kyFtT9BtAXaX+CjrUP6aBXPZWxUvObJXoRTL4cEG91Y5DCXLkpP/6z9tBjzge+z11OGZ1TxH/zQpYMLyBrUjrR24yKFjX/jku2hFr5z2bq/dRtC3+8+//+OFOHqPyYrtF/LhC3jY6cinnY58Sk/wjTjjF5vqBTzcVA+35cNNeLitHqretuHhLj68FqCknpMA4/1Lz4kqxfy3TCvmZkzLwdXlguMDvoeTdEnJ9B9K1e0Gk1ov2QHngB7KO3HEB1DkTFPxz7//L1SnR55HdxjDYkmTebAhKx1sIGPoXh+Ej9uJ96UdL1QXvtcTRdmgV1eOoXLx7gGJylBjyczR8nISlbUwJkVRr6qCzICDORlDwHCwQGsbNsrLxIyC+rTMes4Io/q3fYrsCkKbNmiz5AfC0G6pM1SCD025oW3ZwAkATAHqVtu3k4lQ56sAVW6/YNM2onuDQfrBuhEo4Uw6OtfN3/D6utGrLtYOXxXq0Th0/2xd890LiqeoWdLEEFTKefGg8ZhCYZw51lLt/hKNVNs90Cv01aHD57jG8+Vs5MdDqcoymClrcpY/w02xITq429GVllo2UxyDuZlrpigbj3EHP4/7C2myVjvo1JoP2C3Nzq3xq9IEWhNEKgzLH2BCeyOnQUG7IwOdHqsW9UwPRvGhCkrhkug++R4ZW5vjXX5z4Y5gzeVyqnxNFx2Q1L+RNzShC70PyyvwdhW5xAYrA5KmvfoCmUvJdOUE89sgZRpbBOLrym0GIfhHgdUxoGqEVSKrThDSpQvg8F6clcDCeKZFYh3t2RDmYZ4M5qPoS+lIUQd+xWhpw69stGUDVeKxwqvQkWiJxqYloyhpVC5ZYCxpCz1D1YHn49qAAydv00LQOZnwxPfFQpIhF7aE2QKWVmMj7ihZxYeSMVE4kvVUMNftwp8hXnWegrsl0ruIwanE8KZbFWTUMKYDijJHsnJSPeSCYrzXiNucRjSVXb6fpcvHwdPTOd4M5y/EMUYR45YZYM0YTfkYegD6NlxL+KTXCFNhOZLXfTYcjMHySRDM5ciCsRwAPWM7wnso1haPFV+Rt507WaCzuqyMrGqtQnEXUegvS+2sSrVGbUd6tKHP4WdX5HBynSJ+9fZIsWO6mULmkMvDBUYBK7ooI1lVUQIhrWtVtOC//hP/zwgY0I2yOApaXrmLwtECA/O2nOrQgQq8K7sOZkgnn0bMGivrAlipSinsRrZ8uSAIT15WQLwfZ2GLRW4cXnBbNAAF+VEYIVPvCZ44gG7wvDv5giqKIJgNoStoOteZq7tiXAhklXsY6w90ubPFvnGoq1V1pKuFiJeWQJFe8K3NuAliHnHKya65WLJGYS8ECIByx6TXB/y17OI2CchvEuFVaklGd59UnPOEfAIj2scxWHhEOwEO3oopd7OvtXT7Zkw5u7KweD9j5fWMUmx11cqbJUmW1gcaRTaespvNH9tDzBmKlkNd/qYouRVSRmA4VB8osa8VW/lJLulYz0QOzr2JfV8BUzLJnuADknxlIOaTltwnb/k6pIZkw7TxbiegFwPXGTAwb3YsDenyFqnaA3rKlTlyYtys+DdYjwtKMcNJyoB+6TcNGvhiXnVvsrlDQFsoFXc5sdbPzgRxyL1kb/588P70cjA8ev36nLaLlvPPc7B8uCux/taHjQ3xOmL3IJqDrKYIMOW1G/pmLHW7AIh5lN1OJe+TYcyZu4fjOYUFkz6dueLc7U/P5H08dCE+XcmT/CXEjcOZm6Ba5JNWFEdxKFbKh6kogOGUHHx36LYLvomcPoNUK72chEdSHuGnO2v6pbcGqTl/1d1B3ODjVwbl723oq4rrZqNnJCuBTHKusb5USLXW76+/0QEqPHbbxZVxGwMDDgkzSgn56GWmipP0/eX2PbNVRyvWMG7u53M8SLrmPqDfKEVljFWylZy/qEjeU4SDK6y/Sjh7Jy+hpkMamJBOqVibuAEoU8R+3RZWSRbM136DINPx/d9t/6u4sOuvoTcHV32zC8Zq6CRjHAFZZsq60IkxPCdnpnm0SiJ6D09LEWBi4P7/CwUu0MHpmyDV/oK2gogfMAUEHRzElQR/1RcKzI0zC/6ug778UYyS827a4zc+drCSqNjEtDk8a33xmuIatzcCwemr6Au8btN3s+H/AYZOAOASAsaC9A3qz7SfwJsbx3juXT/nLyPC205ru8oxOMBj+QJ72YIiK/jvHvxXV+wi8qUvBuIvHECMZeHxxuEB3duNWx7vd8Ten7bdLbGFnzjt7In27SaV2ICJHNqfPsJQ+Vl052ME/5WijYXBq8C+ivb8NHVT8RZQP97WiCtrBXl+tj45gaoooehHHvYL9WUTczeBrmyzY0G4rCgJN0twI+apj4KgcLZCg7hFtMbFF7NlkspwDHQB0hOCxZ27ZOYzQJYdokB5mkdQLV3S4ReuSiqHhonxRaWuCeroCROWnyznHqPWI+iM7oSnA5p4p4c88a62IhK5s4vIUXqAxZUIPEMESAaBD9TyqN/2Di/eQU0HG+BRs/PpmdOSqPKl4/QcwVzogB906LSszT7ylaHf3DAYn5vjkCAZ93fsnTfB219FX+3r982UDcv2o2AoX7WvZ02OvnDRcn7d3lurfOet5Tx9cIUNuFZx+y3XHm/IqLXWNM9NQH3h2aCD4ccYPYIPY22DUTjCGkb+Jie8OJz4gSMX9rYcPJdfe9OK2vrEOvIhj5A3cNHpVl8ouoxy32QTHFwpT7b4j/+pR5DbKTKsBfFn7kuPhqQU31BfGJ4k5kU0jqRFE6XFLvcBnLLm6K4fqmjLjGG+6MwOpXsEFJhzrZRcCjCaEewsiqIC2HxhYE7TeEv0Nj13tV++FZKFAINcuqj8ZqsOXlY5kWTfW2Dg5xGfLTos2wZY5yWiz1vhJFLvBxu6aRxIaejUiINkMYvicK0Axo8Uv6j+IkGjIqTCcZNnjWeVx92ecmYvd2JDypY6C0PSqG+D0F8OE3gGJ1n448AN6ZxFjUs2UA7/+BGw+UXD+Xj5hg5eiPypkBkgklWNToLQvlNJ+85zp2XvWXHRDdya6nKbuVYlo9SyKy6Kt1iorc5+eaLVcwnQ+tmWqHziXO8/N4KH/Yo9030jQbL/lORM43Co3y/kIPV6/EWDN3E0eyOzEL7hNATtFZnDUnvG2d3J+MWrfu4IsV5cymvJaG9ewPLEsbztBU6D29nXZ0nRp/CytImbXoCpN+A9UMHc93KeM3bq81Br5bPiHe66dfqCdrqtUub2dl01DBpB/PPv/0CXjXuorqMv3kgB4aR9GMispjiC7sDw8aayliMQoejneImaSmFWLeDnikBFr/pZsTH8xMoNHInRKK1Xvs2/BgvZZHYZCG0vJ/2rayvdVrMh3dGOm/YLd/wZtNIxX9v28mW7ftiuZw1cXffVDWxZtlZVI0AUGNqZ/LGvP+3KmSUl9dApoV6vOOkDf9ud607xFVRAG21eXy+nyXnwpFMSlughEBq6q+kyGblRGYHid5PA87NovPoCEqsFbUKxQ3j9yjYvaBNNTyL3mXE7y6iV21zxV/4opkQpXEUU7peOSrA8l+FygLLq0WuCFGALnQytVG2k2B80px4yDVr9ZYe6HOLhx4vXGo0sHu0FVD9jocPt9r/oXE7oq4kSKK/nI9+A7WFxN8RyVe2vrSOJF3maoePie3pe0vpU3BFIzDiN0ugmdhfTVXFG6JJbQJWFlshs57YqCc33rEWX6hQ2lsICe00APiaSe+BfaZboQymhQCMAIp6X6wXV9Ev6d6Ynw4Z4I35piJ+deq9UqZOr41WRnTrPp42Wds46j1hkBj7joZWNBi73xZKO5CIkMl1N4CMqbgwC/h3a6bdaOO0rT0BAEZqp+EcWzODS5LEzt7BTzhlueHOBLCEU/pcnaXQ/Bt5XLRwsw/JUOF2LE9L4Rz0bXhjk2UcWwU7w5X7JnDaWRBKeWX57PZt5xjn0bRaLZU5BwIj4FyRgZyxg1toVBF7fJ21I/GPcgUItlVCmqEwNZaql9d2X6Buj92JCtplNhUjIzKXSNFJMLN8JSqx64z9KC2p94vvrR2P2k4kxux+0GunhK+mMHNHur074K+l9bQaV3X9RSMpJYiTHqZEuCrpURhXPjRxwlWuGvtradDNBF84FWa5cS5yQc+Z/oQ9Qe2IC2I6Tmj0d5rTz6w2qL0I3Rd2FFEmn4UpR/7CdnUxSqhvnF/ton+nLlE65eqicrlYHqk8MwZeyQtWgYEk26FFDjMBjLdvsJ79LfmYSvdxkOWJvDx0MGR28C9JpNYEe8mvMV+Nmme+Pz5RkH9bxl2VCyl9NVEdzSpptWacgFAynrsyejFDRoUI/5/oAt4qYlnRnHNlmdSsjqBoCHGwQ9KIhFP0yeZmBfbuCfZVCny5SyPtqlMrQV2VUagPiYuuUp9JoSswpxcGoxikPWO2cwrkq0USG4vnrPfwQFaTOQFGRW9oejo2qjwaC9b4F0s8YCV7VAOOgg+R8f4Pxki92INS/b32z0SgjowuEr3UhWNzq7zjKa3GA+1utDRUPddF5KgYMoKXM4fjl92r1F6NRzOoypkNJXjhjdV9CDtB/40VjjFRQtOHwAP8tQJPdIHxEkHqA6PzwAO9m1rc9fHpG9z3gW3oug4y4enj1IgbFeBbwkLcuPB9tPG+ANPCGO1BQzQQTEPudAu4nHswJCT1DbPo3letxsMHlrCHEdJWPNQB1o4++0Eff51N0OPAqG8FMkw/H0lMZjm0hQ7CUrkLftjuFMNTLl/JSPa2YWOOJJB5jULjsDpkN/oLrhnzyC87IQ4FABJZFx2yMxAuFon+YnfH+Fu2TEaVMPgcL3Nb6jBof/sa4qwy4Uq5TpaNBlyuP3PmcdvFs34zeeVFauanF6FFQXvB7/J58SX38fjCMlZp/NP6p8WgRiPFOcmGrkB/nXVB+2gzUtIxEM7yMI8sz28j7tmoX+W00KzJSMLuRy7shJTaYgXefbKg7q8PoJmotOD8wTLPmzL2+vW1zs2+nq8h+eIHpZO/xm8Ogl8foj7+hnJGMKm5l/lyyXKCINhdT3rGsvAqAu/ojgh9UJC+Fyi98C3ZK9ZS/L0CZKiZrbhDIiRxEz/GkWiJ+fHgwdw3/FtRXUhVXAIR1A4gnKTCkF/G+7eiw3TnYGB3y5qL0GkujAVZ1BO6kRhExSrX5Un5eMgUI71FGNeqC8pI8WlleTgLK9hzaYcNRdbNRaQT8NaOSKbvmoIoD0oUeH88mjedC7cmyEdfsBAuyRjNgfL5JAGB99EFJzLkPRnxAUTne/u9U6Hrgow6eyLTfUk8Sjz0WHVGcFboAKsgfgwHxlmcCDBTKVTxUb8wQNwe3HfMLIbginM9Q6AEI5Yd5PlU7WYReChnZbEloR4VOTVHDT54S3quh/dtp9/BIHIe+a6RWtKwDrU/ZgTe20TEgUTxkzBiei6P85ZCa3KEn/I5HmNes0CGfynXniTjmK99fquEeoxsV4k0luI2Uiu6WPDoqd+ZdI5nYHBzmO7t6nrzrDnOCwYDaUY00rAzo7BiJGOOXHDAcc4eZmSDfIjvf7HFQrmWCcf431s5NbgIlS8wPP9Z69b/+H/gc6os5PyMX6hRWcQRLcI5aH4+N0/mQzN5Rt9q4K+wgrUrLDTbossPxqoWogLb9wX6iyA9HQOzPtNnL+SqYxQbjnEfqSkJ2jLAyaXLdCR5TSaNeAlgt+VdQ/VlncvvWyL81bQDPuIhTJPp5FGVJmJ9wy8dyd7XvtKyLxRhu8aWebwd/Hl4e/eFv+sfR+zPjx9npRfYL98/+VnYfGfgVGcayRf+JAweh/oXRodwi3WBMRnfqlbqQ8lbQh/8Pk7+tkA=='
PAYLOAD_SHA = '71876acec5d71ebf3ddabe571cc1d9f361ade68f7a8daf6785963caf6e65e336'
NAMES = (
    'server/booking-checkout.php',
    'views/booking-payment.php',
    'payment-assets/booking-payment.css',
    'payment-assets/booking-payment.js',
    'server/booking-pay.php',
)


def need(ok, message):
    if not ok:
        raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data.replace(b'\r\n', b'\n')).hexdigest()


def payload():
    raw = zlib.decompress(base64.b64decode(PAYLOAD))
    need(hashlib.sha256(raw).hexdigest() == PAYLOAD_SHA, 'Installer payload checksum failed.')
    files = json.loads(raw.decode('utf-8'))
    need(set(files) == set(NAMES), 'Unexpected installer payload paths.')
    return {name: content.encode('utf-8') for name, content in files.items()}


def safe_path(root, name):
    path = root / name
    need(not Path(name).is_absolute() and '..' not in Path(name).parts, 'Unsafe release path.')
    for item in [path] + list(path.parents):
        need(not item.is_symlink(), 'Symbolic link in deployment path: ' + str(path))
    return path


def preflight(root, public, files, php):
    need(root.is_dir() and not root.is_symlink(), 'Private application directory not found.')
    release = safe_path(root, 'calendar-confirmation-release.json')
    need(release.is_file(), 'Current release manifest is missing.')
    manifest = json.loads(release.read_text())
    need(manifest.get('release') == 'sitesee-calendar-confirmation-test-v1', 'Unexpected active release.')
    recorded = manifest.get('files', {})
    need(all(name in recorded for name in ('server/booking-pay.php', 'server/booking-store.php',
         'server/booking-crm.php', 'tools/booking-communications.php')), 'Booking release is incomplete.')
    for name, expected in recorded.items():
        target = safe_path(root, name)
        need(target.is_file() and digest(target.read_bytes()) == expected,
             'Active release check failed: ' + name)
    for name, content in files.items():
        target = safe_path(root, name)
        previous = OLD_PAYMENT_SHA if name == 'server/booking-pay.php' else None
        if target.exists():
            need(target.is_file() and digest(target.read_bytes()) in (previous, digest(content)),
                 'Existing server file differs from the reviewed versions: ' + name)
        else:
            need(previous is None, 'Existing payment entry point is missing.')
    branded = safe_path(root, 'branded-checkout-release.json')
    if branded.exists():
        expected = {'release': 'sitesee-branded-checkout-test-v1',
                    'files': {name: digest(data) for name, data in files.items()}}
        need(branded.is_file() and json.loads(branded.read_text()) == expected,
             'Existing branded checkout manifest differs from this release.')
    for name in ('assets/images/sitesee-logo.png', 'assets/fonts/Inter-Regular.ttf',
                 'assets/fonts/Poppins-SemiBold.ttf', 'booking-pay.php'):
        need((public / name).is_file(), 'Existing public asset is missing: ' + name)
    with tempfile.TemporaryDirectory(prefix='sitesee-payment-lint-') as directory:
        for name, content in files.items():
            if name.endswith('.php'):
                file = Path(directory) / Path(name).name
                file.write_bytes(content)
                result = subprocess.run([php, '-l', str(file)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                need(result.returncode == 0, 'PHP syntax check failed: ' + name)
    return manifest


def existing_config(root):
    path = safe_path(root, 'booking-checkout.json')
    if not path.exists():
        return None
    need(path.is_file() and path.stat().st_size <= 4096 and path.stat().st_mode & 0o077 == 0,
         'Existing checkout configuration is not private.')
    config = json.loads(path.read_text())
    need(config.get('stage') == 'TEST' and isinstance(config.get('enabled'), bool)
         and re.fullmatch(r'pk_test_[A-Za-z0-9]{12,512}', config.get('publishable_key', '')),
         'Existing checkout configuration differs; nothing replaced.')
    return config


def atomic_write(path, content, uid, gid, mode=0o600):
    fd, temp = tempfile.mkstemp(prefix='.payment-install-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp, mode)
        os.chown(temp, uid, gid)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def install(root, public, files, php, config, uid, gid):
    manifest = preflight(root, public, files, php)
    # Server state is validated again immediately before mutation.
    prior_config = existing_config(root)
    need(prior_config is None or prior_config == config, 'Checkout settings changed during preparation.')
    updated = dict(manifest)
    updated['files'] = dict(manifest['files'])
    # Legacy calendar checkers accept only server/tools PHP/Python entries.
    # The separate checkout manifest also covers the view, CSS and JavaScript.
    updated['files'].update({name: digest(content) for name, content in files.items() if name.startswith('server/')})
    branded = {'release': 'sitesee-branded-checkout-test-v1',
               'files': {name: digest(content) for name, content in files.items()}}
    config_bytes = (json.dumps(config, indent=2) + '\n').encode()
    release_bytes = (json.dumps(updated, indent=2) + '\n').encode()
    changes = [(name, files[name]) for name in NAMES if name != 'server/booking-pay.php']
    if prior_config is None:
        changes.append(('booking-checkout.json', config_bytes))
    changes += [('branded-checkout-release.json', (json.dumps(branded, indent=2) + '\n').encode()),
                ('calendar-confirmation-release.json', release_bytes),
                ('server/booking-pay.php', files['server/booking-pay.php'])]
    changes = [(n, b) for n, b in changes if not (root / n).is_file() or (root / n).read_bytes() != b]
    if not changes:
        return None
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup = root / 'deployment-backups' / ('branded-checkout-' + stamp)
    safe_path(root, 'deployment-backups')
    backup.mkdir(parents=True, mode=0o700)
    original = {}
    for name, _ in changes:
        target = safe_path(root, name)
        if target.exists():
            original[name] = target.stat()
            saved = backup / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(str(target), str(saved))
        else:
            original[name] = None
    (backup / 'restore-paths.json').write_text(json.dumps({n: original[n] is not None for n, _ in changes}, indent=2))
    written = []
    try:
        for name, content in changes:
            target = safe_path(root, name)
            if not target.parent.exists():
                target.parent.mkdir(mode=0o700)
                os.chown(str(target.parent), uid, gid)
            atomic_write(target, content, uid, gid)
            written.append(name)
        preflight(root, public, files, php)
        need(existing_config(root) == config, 'Installed checkout configuration verification failed.')
    except Exception:
        for name in reversed(written):
            target = root / name
            previous = original[name]
            if previous is None:
                target.unlink()
            else:
                atomic_write(target, (backup / name).read_bytes(), previous.st_uid, previous.st_gid,
                             previous.st_mode & 0o777)
        raise
    return backup


def main():
    need(sys.argv[1:] in ([], ['--check']), 'Run without arguments to install, or --check to inspect only.')
    need(os.geteuid() == 0, 'Run in WHM Terminal as root.')
    account = pwd.getpwnam('sitesee')
    need(ROOT.is_dir() and ROOT.stat().st_uid == account.pw_uid and ROOT.stat().st_mode & 0o022 == 0,
         'Private application ownership or permissions differ.')
    os.umask(0o077)
    lock = os.open(str(ROOT), os.O_RDONLY | os.O_DIRECTORY)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        files = payload()
        preflight(ROOT, PUBLIC, files, PHP)
        print('Package, existing release, public brand assets and PHP syntax: PASS', flush=True)
        if sys.argv[1:] == ['--check']:
            print('Inspection only. No server files or settings changed.')
            return
        config = existing_config(ROOT)
        if config is None:
            print('Use the TEST publishable key from the same Stripe account as the existing test payments.', flush=True)
            key = getpass.getpass('Stripe TEST publishable key (pk_test_..., hidden): ').strip()
            need(re.fullmatch(r'pk_test_[A-Za-z0-9]{12,512}', key), 'A pk_test_ publishable key is required. No files installed.')
            config = {'stage': 'TEST', 'enabled': True, 'publishable_key': key}
        backup = install(ROOT, PUBLIC, files, PHP, config, account.pw_uid, account.pw_gid)
        print('\nFINAL RESULTS')
        print('Branded payment page: installed' if backup else 'Branded payment page: already installed')
        print('Embedded checkout: ' + ('enabled in TEST mode' if config['enabled'] else 'existing disabled state preserved'))
        print('Active release hashes: PASS')
        if backup:
            print('Backup: ' + str(backup))
        print('Existing Stripe secret, webhook, mail, calendar, PHP-FPM and live-payment settings unchanged.')
        print('No payment session, charge, email, calendar event or booking was created by installation.')
        print('Next: create one fresh test booking and complete the branded test payment.')
    finally:
        os.close(lock)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('STOP: ' + (str(error) if isinstance(error, RuntimeError) else
              'Installation could not complete. Changed application files were restored where possible.'), file=sys.stderr)
        sys.exit(1)
